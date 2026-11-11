<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Scoring\Attempt;
use LiveScoreTracker\Domain\Scoring\EventScores;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;

/**
 * Everything the report shows for one category.
 */
final class CategoryReport
{
    /**
     * @param array<string, FictitiousChild>                $children by bib
     * @param list<array{variant: Variant, scores: EventScores}> $events
     * @param array<string, array<string, list<Attempt>>>    $attempts variant id => (bib => attempts)
     * @param list<RankedCompetitor>                         $ranking
     */
    public function __construct(
        public readonly Category $category,
        public readonly array $children,
        public readonly array $events,
        public readonly array $attempts,
        public readonly array $ranking,
    ) {}
}
