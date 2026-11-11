<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * A registered child, as stored.
 */
final class Registration
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $bib,
        public readonly RegistrationStatus $status,
        public readonly RegistrationSource $source,
        public readonly ChildData $child,
        public readonly string $createdAt,
        public readonly ?string $registeredAt,
        public readonly ?string $withdrawnAt,
    ) {}

    /**
     * Name shown outside the restricted screens: first name and last-name initial (spec § 15).
     */
    public function publicName(): string
    {
        return $this->child->firstName . ' ' . mb_substr($this->child->lastName, 0, 1) . '.';
    }

    public function fullName(): string
    {
        return $this->child->firstName . ' ' . $this->child->lastName;
    }
}
