<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use DateTimeImmutable;

/**
 * General information about the edition (spec § 12.2).
 *
 * Dates and times are local wall-clock values of the competition venue.
 */
final class Edition
{
    public function __construct(
        public readonly string $name,
        public readonly string $organizer,
        public readonly DateTimeImmutable $date,
        public readonly string $defaultLocale,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly ?string $venue = null,
        public readonly ?string $contactEmail = null,
    ) {}
}
