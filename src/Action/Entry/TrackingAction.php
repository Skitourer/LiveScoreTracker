<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Follow-up of a workshop (spec § 8.6): every registered child of the levels
 * concerned, with attempts made, best result and "to check" marks, in tabs:
 * to do (checked in, attempts left), started, finished, not checked in, all.
 * Sorted by bib, with a search on bib or first name (over all children).
 *
 * On a time workshop, ticking 1 or 2 children starts a race (spec § 8.4).
 */
final class TrackingAction
{
    public const TABS = ['todo', 'started', 'finished', 'absent', 'all'];

    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $query = TextCleaner::key(Responder::query($request, 'q'));
        $tab = $query !== '' ? 'all' : Responder::query($request, 'tab');
        if (!\in_array($tab, self::TABS, true)) {
            $tab = 'todo';
        }
        $byChild = $this->attempts->currentByChild(array_map(static fn($v): string => $v->id(), $workshop->variants));

        $rows = [];
        $counts = array_fill_keys(self::TABS, 0);
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            $variant = $this->desk->variantFor($workshop, $child->child->level);
            if ($variant === null || $child->bib === null) {
                continue;
            }
            $current = $byChild[$child->id] ?? [];
            $progress = new AttemptProgress($variant, $current);
            $tabs = self::tabsOf($progress, $child->isPresent());
            foreach ($tabs as $in) {
                ++$counts[$in];
            }
            if (!\in_array($tab, $tabs, true)) {
                continue;
            }
            if ($query !== '' && (string) $child->bib !== $query && !str_starts_with(TextCleaner::key($child->child->firstName), $query)) {
                continue;
            }
            $toCheck = false;
            foreach ($current as $attempt) {
                $toCheck = $toCheck || $attempt->warnings !== [];
            }
            $rows[] = [
                'child' => $child,
                'variant' => $variant,
                'used' => $progress->used(),
                'finished' => $progress->isFinished(),
                'best' => $progress->best(),
                'to_check' => $toCheck,
            ];
        }

        $timed = $this->desk->isTimed($workshop);

        return $this->responder->render($response, 'entry/tracking.html.twig', [
            'workshop' => $workshop,
            'rows' => $rows,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'query' => Responder::query($request, 'q'),
            'reason' => $rows === [] && $query !== '' ? $this->whyNotListed($workshop, $query) : null,
            // Time workshops: tick 1 or 2 children to start a race.
            'race' => $timed && $this->responder->context->can('enter_results_chosen_event'),
            'bibs_per_race' => $timed ? $this->desk->bibsPerRace($workshop) : 0,
        ]);
    }

    /**
     * @return list<string>
     */
    private static function tabsOf(AttemptProgress $progress, bool $present): array
    {
        $tabs = ['all'];
        if ($progress->isFinished()) {
            $tabs[] = 'finished';
        } else {
            if ($progress->used() > 0) {
                $tabs[] = 'started';
            }
            $tabs[] = $present ? 'todo' : 'absent';
        }

        return $tabs;
    }

    /**
     * Why a search finds nobody: for a bib, the precise reason (unknown, other level); otherwise "no match".
     */
    private function whyNotListed(\LiveScoreTracker\Domain\Config\Workshop $workshop, string $query): string
    {
        if (!ctype_digit($query)) {
            return 'entry.tracking.no_match';
        }
        $resolved = $this->desk->resolve($workshop, (int) $query);

        return \is_array($resolved) ? 'entry.tracking.no_match' : $resolved;
    }
}
