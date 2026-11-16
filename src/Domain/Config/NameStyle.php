<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * How children are named to referees and in the participant area (spec § 12.3):
 * first name + initial by default, or full name.
 */
enum NameStyle: string
{
    case Short = 'short';
    case Full = 'full';
}
