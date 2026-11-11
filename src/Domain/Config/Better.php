<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Which raw result is the better one.
 */
enum Better: string
{
    case Lower = 'lower';
    case Higher = 'higher';

    /**
     * Returns true when $a is strictly better than $b.
     */
    public function isBetter(int $a, int $b): bool
    {
        return $this === self::Lower ? $a < $b : $a > $b;
    }
}
