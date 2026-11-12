<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Measurement\EntryParser;
use LiveScoreTracker\Domain\Registration\AttendanceMark;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Domain\Results\CounterTally;
use LiveScoreTracker\Domain\Results\EntryChecks;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\Author;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Presentation\ResultFormatter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Workshop entry screen, step 2 (spec § 8.2 and § 8.3): the child shown large
 * (first name + initial, level, event), the attempt number and the attempts
 * already made; then the result or "foul", the modifiers, and the warnings
 * to confirm (spec § 10.1). Also corrects or deletes an attempt, and lets an
 * admin or organizer clear every result of the child at this event.
 *
 * Counter events (spec § 8.5) are entered with the live counter, whose counts
 * are sent with kind=counter; typing the total directly stays possible.
 *
 * Results are entered only while the competition runs.
 */
final class EntryChildAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly AttemptRepository $attempts,
        private readonly RegistrationRepository $registrations,
        private readonly ResultFormatter $formatter,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{workshop: string, bib: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $back = '/entry/' . $workshop->id;
        $resolved = $this->desk->resolve($workshop, (int) $args['bib']);
        if (!\is_array($resolved)) {
            $this->responder->flash('error', $resolved, ['bib' => $args['bib']]);

            return $this->responder->redirect($response, $back);
        }
        [$child, $variant] = $resolved;
        $self = $back . '/' . $child->bib;
        $progress = $this->desk->progress($child, $variant);
        $open = $this->responder->context->phase === Phase::Running;
        $correcting = $this->editable($progress, (int) Responder::query($request, 'correct'));
        $form = ['value' => '', 'kind' => 'value', 'modifiers' => [], 'entry_id' => EntryFields::newEntryId(), 'tally' => null];
        if ($correcting !== null) {
            $tally = CounterTally::fromDetails($correcting->details);
            $form['value'] = $correcting->measured === null || $tally !== null ? '' : EntryParser::toDigits($correcting->measured, $variant->measure, $variant->timeFormat);
            $form['modifiers'] = $correcting->modifiers;
            $form['tally'] = $tally;
        }
        $errors = [];
        $warnings = [];

        if ($request->getMethod() === 'POST') {
            $op = Responder::field($request, 'op');
            if (!$open) {
                $this->responder->flash('error', $this->responder->context->phase === Phase::Finished ? 'entry.error.finished' : 'entry.error.not_running');

                return $this->responder->redirect($response, $self);
            }
            if ($op === 'mark_present') {
                $this->registrations->setAttendance($child->id, AttendanceMark::CheckedIn, true, $this->responder->context->user);
                $this->responder->flash('success', 'entry.marked_present', ['name' => $child->publicName()]);

                return $this->responder->redirect($response, $self);
            }
            if ($op === 'clear' && $this->responder->context->can(Permission::EnterResultsAnyEvent) && $progress->attempts !== []) {
                // Admin or organizer: every result of this child at this event, kept in the history.
                foreach ($progress->attempts as $attempt) {
                    $this->attempts->delete($attempt, Author::from($this->responder->context));
                }
                $this->responder->audit('results.cleared', ['bib' => $child->bib, 'event' => $variant->id(), 'count' => \count($progress->attempts)]);
                $this->responder->flash('warning', 'entry.cleared', ['name' => $child->publicName(), 'count' => \count($progress->attempts)]);

                return $this->responder->redirect($response, $self);
            }
            $target = $this->editable($progress, (int) Responder::field($request, 'attempt_id'));
            if ($op === 'delete' && $target !== null) {
                $this->attempts->delete($target, Author::from($this->responder->context));
                $this->responder->flash('warning', 'entry.deleted', ['name' => $child->publicName()]);

                return $this->responder->redirect($response, $self);
            }
            if ($op === 'record' || ($op === 'correct' && $target !== null)) {
                $correcting = $op === 'correct' ? $target : null;
                $kind = Responder::field($request, 'kind');
                $isCounter = $kind === 'counter' && $variant->measure === Measure::Counter;
                $form = [
                    'value' => Responder::field($request, 'value'),
                    'kind' => $kind === 'foul' ? 'foul' : ($isCounter ? 'counter' : 'value'),
                    'modifiers' => EntryFields::modifiers($request, $variant),
                    'entry_id' => EntryFields::entryId($request, 'entry_id'),
                    'tally' => $isCounter ? EntryFields::tally($request, $variant) : null,
                ];
                $measured = null;
                $details = [];
                if ($form['tally'] !== null) {
                    // Counter: the result is the total of the actions, with their detail (spec § 8.5).
                    $measured = $form['tally']->total($variant);
                    $details = $form['tally']->toDetails();
                } elseif ($form['kind'] === 'value') {
                    $measured = EntryParser::parse($form['value'], $variant->measure, $variant->timeFormat);
                    if ($measured === null) {
                        $errors['entry.error.invalid_value'] = [];
                    }
                }
                if ($errors === []) {
                    $warnings = EntryChecks::check($progress, $measured, $child->isPresent(), $correcting === null, $this->clock->now()->getTimestamp());
                    if ($form['tally'] !== null) {
                        $warnings = [...$warnings, ...$form['tally']->warnings($variant)];
                    }
                    if ($warnings === [] || Responder::field($request, 'confirm') === '1') {
                        $modifiers = $measured === null ? [] : $form['modifiers'];
                        $total = EntryFields::modifierTotal($variant, $modifiers);

                        return $this->save($response, $back, $self, $child, $variant, $progress, $correcting, $form['entry_id'], $measured, $modifiers, $total, $warnings, $details);
                    }
                }
            }
        }

        return $this->responder->render($response, 'entry/child.html.twig', [
            'workshop' => $workshop,
            'child' => $child,
            'variant' => $variant,
            'progress' => $progress,
            'rows' => self::rows($this->attempts->history($child->id, $variant->id()), $variant),
            'open' => $open,
            'can_clear' => $open && $progress->attempts !== [] && $this->responder->context->can(Permission::EnterResultsAnyEvent),
            'correcting' => $correcting,
            'form' => $form,
            'errors' => $errors,
            'warnings' => $warnings,
            'interpreted' => $this->interpreted($form, $variant),
            // The counter shows the counts to check instead of starting a new run.
            'counter_review' => $correcting !== null || $request->getMethod() === 'POST',
        ], $errors === [] && $warnings === [] ? 200 : 422);
    }

    /**
     * @param array<string, int>   $modifiers
     * @param list<string>         $warnings
     * @param array<string, mixed> $details
     */
    private function save(
        ResponseInterface $response,
        string $back,
        string $self,
        Registration $child,
        Variant $variant,
        AttemptProgress $progress,
        ?StoredAttempt $correcting,
        string $entryId,
        ?int $measured,
        array $modifiers,
        int $total,
        array $warnings,
        array $details,
    ): ResponseInterface {
        $author = Author::from($this->responder->context);
        $result = $measured === null ? null : $this->formatter->format(max(0, $measured + $total), $variant);
        $params = ['name' => $child->publicName(), 'bib' => (string) $child->bib, 'result' => $result ?? '', 'number' => $progress->nextNumber(), 'of' => $variant->attempts];
        if ($correcting !== null) {
            $this->attempts->correct($correcting, $entryId, $measured, $modifiers, $total, $warnings, $author, $details);
            $this->responder->flash('success', $measured === null ? 'entry.corrected_foul' : 'entry.corrected', $params);

            return $this->responder->redirect($response, $self);
        }
        $this->attempts->record($entryId, $child->id, (int) $child->bib, $variant->id(), $measured, $modifiers, $total, $warnings, $author, $details);
        $this->responder->flash($warnings === [] ? 'success' : 'warning', $measured === null ? 'entry.recorded_foul' : 'entry.recorded', $params);

        // Back to the bib field for the next child.
        return $this->responder->redirect($response, $back);
    }

    /**
     * The attempts as listed under the form: latest version, earlier versions,
     * and the attempt number (a foul in lenient mode does not use up a number).
     *
     * @param list<StoredAttempt> $history
     *
     * @return list<array{number: int|null, counted: bool, latest: StoredAttempt, previous: list<StoredAttempt>}>
     */
    private static function rows(array $history, Variant $variant): array
    {
        $groups = [];
        foreach ($history as $version) {
            $groups[$version->originalId][] = $version;
        }
        $rows = [];
        $used = 0;
        foreach ($groups as $versions) {
            $latest = $versions[\count($versions) - 1];
            $counted = !$latest->isFoul() || $variant->foulMode === \LiveScoreTracker\Domain\Config\FoulMode::Strict;
            $number = null;
            if ($latest->deletedAt === null) {
                $number = $counted ? ++$used : $used + 1;
            }
            $rows[] = ['number' => $number, 'counted' => $counted, 'latest' => $latest, 'previous' => \array_slice($versions, 0, -1)];
        }

        return $rows;
    }

    /**
     * A current attempt of this child on this event, or null.
     */
    private function editable(AttemptProgress $progress, int $id): ?StoredAttempt
    {
        foreach ($progress->attempts as $attempt) {
            if ($attempt->id === $id) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * The value as understood, shown large before validation (spec § 7.3).
     *
     * @param array{value: string, kind: string, modifiers: array<string, int>, entry_id: string, tally: CounterTally|null} $form
     */
    private function interpreted(array $form, Variant $variant): ?string
    {
        if ($form['tally'] !== null) {
            return $this->formatter->format($form['tally']->total($variant), $variant);
        }
        $measured = $form['kind'] === 'value' ? EntryParser::parse($form['value'], $variant->measure, $variant->timeFormat) : null;

        return $measured === null ? null : $this->formatter->format($measured, $variant);
    }
}
