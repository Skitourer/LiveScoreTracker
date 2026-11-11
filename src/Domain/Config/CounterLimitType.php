<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * What ends a counter event (spec § 7.6).
 */
enum CounterLimitType: string
{
    case Duration = 'duration';
    case Shots = 'shots';
}
