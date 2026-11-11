<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Source of the current time (UTC), replaceable in tests.
 */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Database format of a moment: 'YYYY-MM-DD HH:MM:SS' in UTC.
     */
    public function stamp(?DateTimeImmutable $moment = null): string
    {
        return ($moment ?? $this->now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function stampIn(string $modifier): string
    {
        return $this->stamp($this->now()->modify($modifier));
    }
}
