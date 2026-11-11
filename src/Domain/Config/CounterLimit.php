<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * What ends a counter event: a duration (in seconds) or a number of shots.
 */
final class CounterLimit
{
    public function __construct(
        public readonly CounterLimitType $type,
        public readonly int $value,
    ) {}
}
