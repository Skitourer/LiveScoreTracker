<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

use InvalidArgumentException;

/**
 * One try of a child on an event (spec § 7.4).
 *
 * The measured value and the modifiers are kept apart (spec § 7.5);
 * the retained result is their sum.
 */
final class Attempt
{
    /**
     * @param int|null $measured      raw value in storage units; null for a foul
     * @param int      $modifierTotal sum of bonuses and penalties, in storage units
     */
    private function __construct(
        public readonly ?int $measured,
        public readonly int $modifierTotal,
    ) {}

    public static function valid(int $measured, int $modifierTotal = 0): self
    {
        if ($measured < 0) {
            throw new InvalidArgumentException('A measured value cannot be negative.');
        }

        return new self($measured, $modifierTotal);
    }

    public static function foul(): self
    {
        return new self(null, 0);
    }

    public function isFoul(): bool
    {
        return $this->measured === null;
    }

    /**
     * Retained result (measured + modifiers), or null for a foul.
     */
    public function result(): ?int
    {
        return $this->measured === null ? null : max(0, $this->measured + $this->modifierTotal);
    }
}
