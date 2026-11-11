<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Bonus or penalty added to a measured result (spec § 7.5), e.g. "goal scored: -3.00 s".
 */
final class Modifier
{
    /**
     * @param int $value amount in storage units (hundredths of a second, centimetres or points)
     */
    public function __construct(
        public readonly string $id,
        public readonly int $value,
        public readonly bool $repeatable = false,
    ) {}
}
