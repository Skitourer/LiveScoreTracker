<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * Scores of one event for one category, with the bounds used by the scale.
 */
final class EventScores
{
    /**
     * @param list<EntryScore> $entries best score first
     * @param int|null                  $best    best valid result of the category (scale bound)
     * @param int|null                  $worst   worst valid result of the category (scale bound)
     */
    public function __construct(
        public readonly string $variantId,
        public readonly array $entries,
        public readonly ?int $best,
        public readonly ?int $worst,
    ) {}

    public function entry(string $bib): ?EntryScore
    {
        foreach ($this->entries as $entry) {
            if ($entry->bib === $bib) {
                return $entry;
            }
        }

        return null;
    }
}
