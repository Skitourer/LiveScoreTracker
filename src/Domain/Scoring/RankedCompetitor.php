<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

use LiveScoreTracker\Domain\Config\TieBreaker;

/**
 * A line of the overall ranking of a category (spec § 9.5, § 9.6).
 */
final class RankedCompetitor
{
    /**
     * @param int|null        $rank        null for absent children
     * @param TieBreaker|null $decidedBy   criterion that separated this child from equal totals
     * @param bool            $tied        equal total that no criterion could separate: the admin decides (§ 9.6)
     * @param bool            $complete    false while some events are still pending
     * @param array<string, EntryScore|null> $events variant id => score on that event
     * @param bool            $manual      order decided by hand by an admin after an unbreakable tie
     */
    public function __construct(
        public readonly string $bib,
        public readonly ?int $rank,
        public readonly int $total,
        public readonly int $firstPlaces,
        public readonly int $top3Places,
        public readonly ?TieBreaker $decidedBy,
        public readonly bool $tied,
        public readonly bool $complete,
        public readonly bool $present,
        public readonly array $events,
        public readonly bool $manual = false,
    ) {}

    /**
     * The same line with the rank given by an admin's decision.
     */
    public function decidedByHand(int $rank): self
    {
        return new self($this->bib, $rank, $this->total, $this->firstPlaces, $this->top3Places, null, false, $this->complete, $this->present, $this->events, true);
    }
}
