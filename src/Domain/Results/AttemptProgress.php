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

    /**
     * Attempts that count (spec § 10.4): the first ones in chronological order
     * until the allowed number is used up. Later ones stay stored but are
     * excluded, e.g. after the number of attempts went down or the foul mode
     * became strict.
     *
     * @return list<StoredAttempt>
     */
    public function retained(): array
    {
        $kept = [];
        $used = 0;
        foreach ($this->attempts as $attempt) {
            if ($used >= $this->variant->attempts) {
                break;
            }
            $kept[] = $attempt;
            if ($this->counts($attempt)) {
                ++$used;
            }
        }

        return $kept;
    }

    /**
     * Attempts stored but left out of the result.
     *
     * @return list<StoredAttempt>
     */
    public function excluded(): array
    {
        return \array_slice($this->attempts, \count($this->retained()));
    }

    public function used(): int
    {
        $used = 0;
        foreach ($this->retained() as $attempt) {
            if ($this->counts($attempt)) {
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
        foreach ($this->retained() as $attempt) {
            $result = $attempt->result();
            if ($result !== null && ($best === null || ($this->variant->better === Better::Lower ? $result < $best : $result > $best))) {
                $best = $result;
            }
        }

        return $best;
    }

    private function counts(StoredAttempt $attempt): bool
    {
        return !$attempt->isFoul() || $this->variant->foulMode === FoulMode::Strict;
    }
}
