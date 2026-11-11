<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * Lifecycle phase of the edition (spec § 4).
 */
enum Phase: string
{
    case Preparation = 'preparation';
    case Running = 'running';
    case Finished = 'finished';
}
