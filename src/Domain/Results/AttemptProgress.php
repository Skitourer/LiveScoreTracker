<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Variant;

/**
 * Where a child stands on one event (spec § 7.4): attempts used, the next
 * attempt number and the best result so far.
 *
 * In strict mode a foul uses up an attempt; in lenient mode it is recorded but
 * the same attempt number is asked again.
 */
final class AttemptProgress
{
    /**
     * @param list<StoredAttempt> $attempts current attempts of the child on this variant
     */
    public function __construct(
        public readonly Variant $variant,
        public readonly array $attempts,
    ) {}

    public function used(): int
    {
        $used = 0;
        foreach ($this->attempts as $attempt) {
            if (!$attempt->isFoul() || $this->variant->foulMode === FoulMode::Strict) {
                ++$used;
            }
        }

        return $used;
    }

    public function isFinished(): bool
    {
        return $this->used() >= $this->variant->attempts;
    }

    /**
     * Number of the next attempt ("attempt 2 of 3"); above the allowed number when all are used.
     */
    public function nextNumber(): int
    {
        return $this->used() + 1;
    }

    public function best(): ?int
    {
        $best = null;
        foreach ($this->attempts as $attempt) {
            $result = $attempt->result();
            if ($result !== null && ($best === null || ($this->variant->better === Better::Lower ? $result < $best : $result > $best))) {
                $best = $result;
            }
        }

        return $best;
    }
}
