<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Points scale of an event (spec § 9.2) and tie-break criteria (spec § 9.6).
 */
final class ScoringRules
{
    /**
     * @param list<TieBreaker> $tieBreakers
     */
    public function __construct(
        public readonly int $maxScore = 1000,
        public readonly int $floor = 100,
        public readonly int $foulScore = 1,
        public readonly int $dnfScore = 0,
        public readonly array $tieBreakers = [TieBreaker::FirstPlaces, TieBreaker::Top3Places, TieBreaker::Youngest],
    ) {}

    /**
     * Returns a copy with some points values replaced (per-event overrides).
     */
    public function with(?int $maxScore = null, ?int $floor = null, ?int $foulScore = null, ?int $dnfScore = null): self
    {
        return new self(
            $maxScore ?? $this->maxScore,
            $floor ?? $this->floor,
            $foulScore ?? $this->foulScore,
            $dnfScore ?? $this->dnfScore,
            $this->tieBreakers,
        );
    }
}
