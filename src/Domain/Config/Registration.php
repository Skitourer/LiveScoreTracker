<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use DateTimeImmutable;

/**
 * Registration window and bib numbering (spec § 5).
 */
final class Registration
{
    public function __construct(
        public readonly ?DateTimeImmutable $opensAt,
        public readonly ?DateTimeImmutable $closesAt,
        public readonly int $quota,
        public readonly int $firstBib,
    ) {}
}
