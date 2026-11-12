<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Config\Workshop;
use LiveScoreTracker\Domain\Measurement\EntryParser;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Domain\Results\EntryChecks;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\Author;
use LiveScoreTracker\Presentation\ResultFormatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * A race of 1 or 2 children on a time event (spec § 8.4).
 *
 * The page is one form with a block per child: time, "did not finish",
 * modifiers. In "timer" mode the phone's stopwatch (app.js) runs the race
 * (Start, Finish at each crossing, order of arrival with "Swap", "Cancel the
 * race") and fills these blocks for the check screen; in "manual" mode the
 * referee types the times read on an external stopwatch. Without JavaScript
 * the form works as the manual mode.
 *
 * Nothing is saved until every child of the race is valid and its warnings
 * confirmed: the race is validated as a whole.
 */
final class RaceAction
{
    public const MODE_TIMER = 'timer';
    public const MODE_MANUAL = 'manual';

    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly AttemptRepository $attempts,
        private readonly ResultFormatter $formatter,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        if (!$this->desk->isTimed($workshop)) {
            throw new HttpNotFoundException($request);
        }
        $back = '/entry/' . $workshop->id;
        $isPost = $request->getMethod() === 'POST';
        $input = static fn(string $name): string => $isPost ? Responder::field($request, $name) : Responder::query($request, $name);

        $mode = $input('mode');
        if (!\in_array($mode, [self::MODE_TIMER, self::MODE_MANUAL], true)) {
            $mode = $this->desk->defaultsToTimer($workshop) ? self::MODE_TIMER : self::MODE_MANUAL;
        }
        $lanes = $this->lanes($workshop, [$input('bib1'), $input('bib2')]);
        if (\is_string($lanes)) {
            return $this->responder->redirect($response, $back);
        }
        $self = $back . '/race?' . http_build_query([
            'bib1' => $lanes[0]['child']->bib,
            'bib2' => isset($lanes[1]) ? $lanes[1]['child']->bib : '',
            'mode' => $mode,
        ]);
        $open = $this->responder->context->phase === Phase::Running;

        $forms = [];
        foreach ($lanes as $i => $lane) {
            $forms[$i] = ['value' => '', 'dnf' => false, 'modifiers' => [], 'entry_id' => EntryFields::newEntryId(), 'timed' => ''];
        }
        $errors = [];
        $warnings = [];

        if ($isPost) {
            if (!$open) {
                $this->responder->flash('error', $this->responder->context->phase === Phase::Finished ? 'entry.error.finished' : 'entry.error.not_running');

                return $this->responder->redirect($response, $self);
            }
            $results = [];
            foreach ($lanes as $i => $lane) {
                $prefix = ($i + 1) . '_';
                $variant = $lane['variant'];
                $form = [
                    'value' => Responder::field($request, $prefix . 'value'),
                    'dnf' => Responder::field($request, $prefix . 'dnf') === '1',
                    'modifiers' => EntryFields::modifiers($request, $variant, $prefix),
                    'entry_id' => EntryFields::entryId($request, $prefix . 'entry_id'),
                    'timed' => ctype_digit(Responder::field($request, $prefix . 'timed')) ? Responder::field($request, $prefix . 'timed') : '',
                ];
                $forms[$i] = $form;
                // "Did not finish" (fall, stop) is a foul (spec § 8.4).
                $measured = null;
                if (!$form['dnf']) {
                    $measured = EntryParser::parse($form['value'], $variant->measure, $variant->timeFormat);
                    if ($measured === null) {
                        $errors[$i] = 'entry.error.invalid_value';
                        continue;
                    }
                }
                $warnings[$i] = EntryChecks::check($lane['progress'], $measured, $lane['child']->isPresent(), true, $this->clock->now()->getTimestamp());
                $results[$i] = $measured;
            }
            $hasWarnings = array_filter($warnings) !== [];
            if ($errors === [] && (!$hasWarnings || Responder::field($request, 'confirm') === '1')) {
                foreach ($lanes as $i => $lane) {
                    $this->save($lane['child'], $lane['variant'], $lane['progress'], $forms[$i], $results[$i] ?? null, $warnings[$i] ?? []);
                }

                // Back to the bibs for the next race.
                return $this->responder->redirect($response, $back);
            }
        }

        $variants = array_unique(array_map(static fn(array $lane): string => $lane['variant']->id(), $lanes));

        return $this->responder->render($response, 'entry/race.html.twig', [
            'workshop' => $workshop,
            'lanes' => $lanes,
            'mode' => $mode,
            'self' => $self,
            'open' => $open,
            'forms' => $forms,
            'errors' => $errors,
            'warnings' => $warnings,
            'interpreted' => array_map(fn(array $lane, array $form): ?string => $this->interpreted($form, $lane['variant']), $lanes, $forms),
            'different_events' => \count($variants) > 1,
            // After a submission the check screen is shown again, not a new race.
            'review' => $isPost,
        ], $errors === [] && array_filter($warnings) === [] ? 200 : 422);
    }

    /**
     * The children of the race, or a blocking error (already flashed).
     *
     * @param list<string> $bibs as typed (the second one may be empty)
     *
     * @return list<array{child: Registration, variant: Variant, progress: AttemptProgress}>|string
     */
    private function lanes(Workshop $workshop, array $bibs): array|string
    {
        $lanes = [];
        $seen = [];
        foreach (\array_slice($bibs, 0, $this->desk->bibsPerRace($workshop)) as $bib) {
            if ($bib === '') {
                continue;
            }
            $resolved = preg_match('/^\d{1,6}$/', $bib) === 1 ? $this->desk->resolve($workshop, (int) $bib) : EntryDesk::UNKNOWN_BIB;
            if (\is_array($resolved) && $resolved[1]->measure !== Measure::Time) {
                $resolved = EntryDesk::OTHER_GROUP;
            }
            if (\is_array($resolved) && isset($seen[(int) $bib])) {
                $resolved = 'entry.error.same_bib';
            }
            if (!\is_array($resolved)) {
                $this->responder->flash('error', $resolved, ['bib' => $bib]);

                return $resolved;
            }
            $seen[(int) $bib] = true;
            [$child, $variant] = $resolved;
            $lanes[] = ['child' => $child, 'variant' => $variant, 'progress' => $this->desk->progress($child, $variant)];
        }
        if ($lanes === []) {
            $this->responder->flash('error', 'entry.error.no_bib');

            return 'entry.error.no_bib';
        }

        return $lanes;
    }

    /**
     * @param array{value: string, dnf: bool, modifiers: array<string, int>, entry_id: string, timed: string} $form
     * @param list<string>                                                                               $warnings
     */
    private function save(Registration $child, Variant $variant, AttemptProgress $progress, array $form, ?int $measured, array $warnings): void
    {
        $modifiers = $measured === null ? [] : $form['modifiers'];
        $total = EntryFields::modifierTotal($variant, $modifiers);
        $this->attempts->record(
            $form['entry_id'],
            $child->id,
            (int) $child->bib,
            $variant->id(),
            $measured,
            $modifiers,
            $total,
            $warnings,
            Author::from($this->responder->context),
            EntryFields::timerDetails($form['timed'], $measured),
        );
        $this->responder->flash($warnings === [] ? 'success' : 'warning', $measured === null ? 'entry.recorded_foul' : 'entry.recorded', [
            'name' => $child->publicName(),
            'bib' => (string) $child->bib,
            'result' => $measured === null ? '' : $this->formatter->format(max(0, $measured + $total), $variant),
            'number' => $progress->nextNumber(),
            'of' => $variant->attempts,
        ]);
    }

    /**
     * @param array{value: string, dnf: bool, modifiers: array<string, int>, entry_id: string, timed: string} $form
     */
    private function interpreted(array $form, Variant $variant): ?string
    {
        $measured = $form['dnf'] ? null : EntryParser::parse($form['value'], $variant->measure, $variant->timeFormat);

        return $measured === null ? null : $this->formatter->format($measured, $variant);
    }
}
