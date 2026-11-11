<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

use LiveScoreTracker\Domain\Scoring\Attempt;

/**
 * One version of an attempt as stored (spec § 8.3): the measured value (null
 * for a foul), the modifiers ticked, who entered it, and whether it was later
 * corrected (replaced) or deleted.
 */
final class StoredAttempt
{
    /**
     * @param array<string, int> $modifiers modifier id => times applied
     * @param list<string>       $warnings  warnings confirmed when it was entered ("to check")
     */
    public function __construct(
        public readonly int $id,
        public readonly int $originalId,
        public readonly int $registrationId,
        public readonly int $bib,
        public readonly string $variantId,
        public readonly ?int $measured,
        public readonly array $modifiers,
        public readonly int $modifierTotal,
        public readonly array $warnings,
        public readonly string $enteredAt,
        public readonly ?string $username,
        public readonly ?string $refereeName,
        public readonly ?string $replacedAt = null,
        public readonly ?string $deletedAt = null,
        public readonly ?string $deletedBy = null,
    ) {}

    public function isFoul(): bool
    {
        return $this->measured === null;
    }

    /**
     * Current version: neither corrected since nor deleted.
     */
    public function isCurrent(): bool
    {
        return $this->replacedAt === null && $this->deletedAt === null;
    }

    public function isCorrection(): bool
    {
        return $this->originalId !== $this->id;
    }

    /**
     * Retained result (measured + modifiers), or null for a foul.
     */
    public function result(): ?int
    {
        return $this->toScoring()->result();
    }

    public function toScoring(): Attempt
    {
        return $this->measured === null ? Attempt::foul() : Attempt::valid($this->measured, $this->modifierTotal);
    }

    /**
     * Who entered it: the referee's first name, or the account.
     */
    public function author(): string
    {
        return $this->refereeName ?? $this->username ?? '?';
    }
}
