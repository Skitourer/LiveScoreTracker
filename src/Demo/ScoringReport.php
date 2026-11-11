<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Scoring\CategoryRanker;
use LiveScoreTracker\Domain\Scoring\Competitor;
use LiveScoreTracker\Domain\Scoring\EventEntry;
use LiveScoreTracker\Domain\Scoring\EventScorer;

/**
 * Runs the scoring engine on a fictitious competition, one category at a time.
 */
final class ScoringReport
{
    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly FictitiousCompetition $competition,
    ) {}

    public function forCategory(Category $category, bool $closed): CategoryReport
    {
        $children = [];
        foreach ($this->competition->children as $child) {
            if ($child->level === $category->level && $child->gender === $category->gender) {
                $children[$child->bib] = $child;
            }
        }

        $events = [];
        $attempts = [];
        foreach ($this->config->variantsForGroup($category->group) as $variant) {
            $entries = [];
            foreach ($children as $bib => $child) {
                $list = $this->competition->attempts[$variant->id()][(string) $bib] ?? [];
                if (!$closed && self::notDoneYet((string) $bib, $variant->id())) {
                    $list = [];
                }
                $attempts[$variant->id()][(string) $bib] = $list;
                $entries[] = new EventEntry((string) $bib, $child->present, $list);
            }
            $events[] = ['variant' => $variant, 'scores' => EventScorer::score($variant, $entries, $closed)];
        }

        $competitors = array_values(array_map(
            static fn(FictitiousChild $child): Competitor => new Competitor($child->bib, $child->present, $child->birthDate),
            $children,
        ));
        $ranking = CategoryRanker::rank(
            $competitors,
            array_map(static fn(array $event) => $event['scores'], $events),
            $this->config->scoring->tieBreakers,
        );

        return new CategoryReport($category, $children, $events, $attempts, $ranking);
    }

    /**
     * While the competition is running, about a third of the events are not done yet.
     */
    private static function notDoneYet(string $bib, string $variantId): bool
    {
        return crc32($bib . '|' . $variantId) % 3 === 0;
    }
}
