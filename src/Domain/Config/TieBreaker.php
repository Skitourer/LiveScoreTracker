<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Criteria used, in order, to separate equal totals (spec § 9.6).
 */
enum TieBreaker: string
{
    case FirstPlaces = 'first_places';
    case Top3Places = 'top3_places';
    case Youngest = 'youngest';
}
