<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Kind of raw result an event produces (spec § 7.3).
 */
enum Measure: string
{
    case Time = 'time';
    case Distance = 'distance';
    case Score = 'score';
    case Counter = 'counter';

    /**
     * Factor between the unit used in the configuration (seconds, metres, points)
     * and the integer stored in the database (hundredths, centimetres, points).
     */
    public function storageFactor(): int
    {
        return match ($this) {
            self::Time, self::Distance => 100,
            self::Score, self::Counter => 1,
        };
    }
}
