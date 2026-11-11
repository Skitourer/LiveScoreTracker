<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * A child's attempts on one event, as input of the scorer.
 */
final class EventEntry
{
    /**
     * @param list<Attempt> $attempts in the order they were made
     */
    public function __construct(
        public readonly string $bib,
        public readonly bool $present,
        public readonly array $attempts = [],
    ) {}
}
