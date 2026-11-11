<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Whether a foul attempt uses up an attempt (spec § 7.4).
 */
enum FoulMode: string
{
    case Strict = 'strict';
    case Lenient = 'lenient';
}
