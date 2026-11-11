<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use DateTimeImmutable;

/**
 * A generated child: never a real person (spec § 25.2).
 */
final class FictitiousChild
{
    public function __construct(
        public readonly string $bib,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $level,
        public readonly string $gender,
        public readonly string $school,
        public readonly DateTimeImmutable $birthDate,
        public readonly bool $present,
    ) {}

    /**
     * Displayed name: first name and last-name initial only (spec § 15).
     */
    public function displayName(): string
    {
        return $this->firstName . ' ' . mb_substr($this->lastName, 0, 1) . '.';
    }
}
