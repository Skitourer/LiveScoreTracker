<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use DateTimeImmutable;
use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Domain\Scoring\CategoryRanker;
use LiveScoreTracker\Domain\Scoring\Competitor;
use LiveScoreTracker\Domain\Scoring\EventEntry;
use LiveScoreTracker\Domain\Scoring\EventScorer;
use LiveScoreTracker\Domain\Scoring\EventScores;
use LiveScoreTracker\Domain\Scoring\ManualTieBreak;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Persistence\TieDecisionRepository;

/**
 * Scores and rankings of one category, always recomputed from the stored
 * attempts (spec § 9.7): per event (§ 9.2 to 9.4) and overall (§ 9.5, 9.6),
 * with the ties an admin ordered by hand.
 */
final class Standings
{
    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly TieDecisionRepository $tieDecisions,
    ) {}

    /**
     * @param bool $closed after closing, events not done count as DNF; before, they are "to do"
     *
     * @return array{
     *     category: Category,
     *     children: array<int, Registration>,
     *     events: list<array{variant: Variant, scores: EventScores, attempts: array<array-key, list<StoredAttempt>>}>,
     *     ranking: list<RankedCompetitor>
     * }
     */
    public function forCategory(Category $category, bool $closed): array
    {
        /** @var array<int, Registration> $children by bib */
        $children = [];
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            if ($child->bib !== null && $child->child->level === $category->level && $child->child->gender === $category->gender) {
                $children[$child->bib] = $child;
            }
        }

        $variants = $this->config->variantsForGroup($category->group);
        $byChild = $this->attempts->currentByChild(array_map(static fn(Variant $v): string => $v->id(), $variants));

        $events = [];
        foreach ($variants as $variant) {
            $entries = [];
            $attempts = [];
            foreach ($children as $bib => $child) {
                $list = array_values(array_filter(
                    $byChild[$child->id] ?? [],
                    static fn(StoredAttempt $attempt): bool => $attempt->variantId === $variant->id(),
                ));
                $attempts[(string) $bib] = $list;
                $entries[] = new EventEntry((string) $bib, $child->isPresent(), array_map(static fn(StoredAttempt $a) => $a->toScoring(), $list));
            }
            $events[] = ['variant' => $variant, 'scores' => EventScorer::score($variant, $entries, $closed), 'attempts' => $attempts];
        }

        $competitors = [];
        foreach ($children as $bib => $child) {
            $birth = $child->child->birthDate === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $child->child->birthDate);
            $competitors[] = new Competitor((string) $bib, $child->isPresent(), $birth === false ? null : $birth);
        }
        $ranking = ManualTieBreak::apply(
            CategoryRanker::rank(
                $competitors,
                array_map(static fn(array $event): EventScores => $event['scores'], $events),
                $this->config->scoring->tieBreakers,
            ),
            array_map(static fn(array $decision): array => $decision['bibs'], $this->tieDecisions->forCategory($category->id())),
        );

        return ['category' => $category, 'children' => $children, 'events' => $events, 'ranking' => $ranking];
    }
}
