<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Results\GlobalChecks;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Domain\Scoring\ManualTieBreak;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\LateEntry;
use LiveScoreTracker\Infrastructure\Persistence\LateEntryRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * What the control board shows (spec § 10, § 10.2): results "to check" not
 * verified yet, ties nobody could break, entries arrived after the closing,
 * and, before closing, the present children with events still missing.
 */
final class ControlBoard
{
    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly LateEntryRepository $lateEntries,
        private readonly Standings $standings,
    ) {}

    /**
     * Results to check, not verified yet, by bib then event.
     *
     * @return list<array{attempt: StoredAttempt, child: Registration, variant: Variant, codes: list<string>}>
     */
    public function toCheck(): array
    {
        $variants = $this->config->variants();
        $byVariant = [];
        foreach ($variants as $variant) {
            $byVariant[$variant->id()] = $variant;
        }
        $children = $this->children();
        $byChild = $this->attempts->currentByChild(array_keys($byVariant));

        $samples = [];
        foreach ($children as $child) {
            $samples[] = [
                'attempts' => $byChild[$child->id] ?? [],
                'category' => $child->child->level . '-' . $child->child->gender,
            ];
        }
        $computed = GlobalChecks::check($variants, $samples);

        $items = [];
        foreach ($children as $child) {
            foreach ($byChild[$child->id] ?? [] as $attempt) {
                $codes = array_values(array_unique([...$attempt->warnings, ...$computed[$attempt->id] ?? []]));
                if ($codes === [] || $attempt->isChecked() || !isset($byVariant[$attempt->variantId])) {
                    continue;
                }
                $items[] = ['attempt' => $attempt, 'child' => $child, 'variant' => $byVariant[$attempt->variantId], 'codes' => $codes];
            }
        }
        usort($items, static fn(array $a, array $b): int => [$a['attempt']->bib, $a['variant']->id(), $a['attempt']->id] <=> [$b['attempt']->bib, $b['variant']->id(), $b['attempt']->id]);

        return $items;
    }

    /**
     * Ties of every category: unbroken ones (the admin must order them) and
     * those already ordered by hand. While the competition runs, a tie is only
     * listed once the tied children have done all their events: before, it is
     * likely to change.
     *
     * @param bool $closed compute the rankings as after the closing
     *
     * @return list<array{category: Category, children: array<int, Registration>, unresolved: list<non-empty-list<RankedCompetitor>>, decided: list<non-empty-list<RankedCompetitor>>}>
     */
    public function ties(bool $closed): array
    {
        $result = [];
        foreach ($this->config->categories() as $category) {
            $standings = $this->standings->forCategory($category, $closed);
            $decided = [];
            foreach ($standings['ranking'] as $line) {
                if ($line->manual) {
                    $decided[$line->total][] = $line;
                }
            }
            $unresolved = array_values(array_filter(
                ManualTieBreak::unresolved($standings['ranking']),
                static fn(array $group): bool => $closed || array_filter($group, static fn(RankedCompetitor $line): bool => !$line->complete) === [],
            ));
            if ($unresolved !== [] || $decided !== []) {
                $result[] = [
                    'category' => $category,
                    'children' => $standings['children'],
                    'unresolved' => $unresolved,
                    'decided' => array_values($decided),
                ];
            }
        }

        return $result;
    }

    /**
     * Entries arrived after the closing, waiting for a decision.
     *
     * @return list<array{entry: LateEntry, child: Registration|null, variant: Variant|null}>
     */
    public function lateEntries(): array
    {
        $items = [];
        foreach ($this->lateEntries->pending() as $entry) {
            $variant = null;
            foreach ($this->config->variants() as $candidate) {
                if ($candidate->id() === $entry->variantId) {
                    $variant = $candidate;
                }
            }
            $items[] = ['entry' => $entry, 'child' => $this->registrations->findByBib($entry->bib), 'variant' => $variant];
        }

        return $items;
    }

    /**
     * Present children with events not done yet (spec § 4, alert before closing).
     *
     * @return list<array{child: Registration, missing: list<Variant>}>
     */
    public function missing(): array
    {
        $variants = $this->config->variants();
        $done = [];
        foreach ($this->attempts->currentByChild(array_map(static fn(Variant $v): string => $v->id(), $variants)) as $childId => $list) {
            foreach ($list as $attempt) {
                $done[$childId][$attempt->variantId] = true;
            }
        }
        $result = [];
        foreach ($this->children() as $child) {
            if (!$child->isPresent()) {
                continue;
            }
            $missing = array_values(array_filter(
                $this->config->variantsForGroup($this->config->groupOf($child->child->level)),
                static fn(Variant $variant): bool => !isset($done[$child->id][$variant->id()]),
            ));
            if ($missing !== []) {
                $result[] = ['child' => $child, 'missing' => $missing];
            }
        }

        return $result;
    }

    /**
     * Number of items waiting on the board: results to check, unbroken ties, late entries.
     */
    public function pendingCount(bool $closed): int
    {
        $ties = 0;
        foreach ($this->ties($closed) as $category) {
            $ties += \count($category['unresolved']);
        }

        return \count($this->toCheck()) + $ties + $this->lateEntries->countPending();
    }

    /**
     * Registered children with a bib, by bib.
     *
     * @return list<Registration>
     */
    private function children(): array
    {
        $children = array_values(array_filter(
            $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]),
            static fn(Registration $child): bool => $child->bib !== null,
        ));
        usort($children, static fn(Registration $a, Registration $b): int => $a->bib <=> $b->bib);

        return $children;
    }
}
