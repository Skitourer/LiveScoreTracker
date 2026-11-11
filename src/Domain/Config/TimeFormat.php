<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * How a time is typed and displayed.
 */
enum TimeFormat: string
{
    case Seconds = 'ss.cc';
    case Minutes = 'm:ss.cc';
}
