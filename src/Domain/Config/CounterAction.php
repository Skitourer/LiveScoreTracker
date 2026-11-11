<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * One scoring action of a counter event (spec § 7.6), e.g. "basket: 3 points".
 */
final class CounterAction
{
    public function __construct(
        public readonly string $id,
        public readonly int $points,
    ) {}
}
