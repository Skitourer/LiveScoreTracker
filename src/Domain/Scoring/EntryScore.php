<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * Score of one child on one event.
 */
final class EntryScore
{
    /**
     * @param int|null $best  best valid result in storage units (null without a valid attempt)
     * @param int|null $score points, null while pending or when absent
     * @param int|null $rank  rank on this event within the category (ties share a rank)
     */
    public function __construct(
        public readonly string $bib,
        public readonly EntryStatus $status,
        public readonly ?int $best,
        public readonly ?int $score,
        public readonly ?int $rank,
    ) {}
}
