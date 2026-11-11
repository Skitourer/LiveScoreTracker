<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Level + gender, e.g. "girls CM1": the unit for scores and rankings (spec § 2).
 */
final class Category
{
    public function __construct(
        public readonly string $level,
        public readonly string $gender,
        public readonly string $group,
    ) {}

    /**
     * Stable identifier, e.g. "CM1-girl".
     */
    public function id(): string
    {
        return $this->level . '-' . $this->gender;
    }
}
