<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

use DateTimeImmutable;

/**
 * A child of the category, as input of the ranking.
 */
final class Competitor
{
    public function __construct(
        public readonly string $bib,
        public readonly bool $present = true,
        public readonly ?DateTimeImmutable $birthDate = null,
    ) {}
}
