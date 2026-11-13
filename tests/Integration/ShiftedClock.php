<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use DateTimeImmutable;
use LiveScoreTracker\Infrastructure\Clock;

/**
 * Real time moved forward by a number of seconds chosen by the test.
 */
final class ShiftedClock extends Clock
{
    public int $offsetSeconds = 0;

    public function now(): DateTimeImmutable
    {
        return parent::now()->modify(\sprintf('%+d seconds', $this->offsetSeconds));
    }

    public function advance(int $seconds): void
    {
        $this->offsetSeconds += $seconds;
    }
}
