<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * Outcome of checking a registration: clean data, or errors; warnings in both cases.
 */
final class ValidationResult
{
    /**
     * @param array<string, array<string, string|int>> $errors   translation key => parameters
     * @param array<string, array<string, string|int>> $warnings translation key => parameters
     */
    public function __construct(
        public readonly ?ChildData $data,
        public readonly array $errors,
        public readonly array $warnings,
    ) {}

    public function isValid(): bool
    {
        return $this->data !== null;
    }
}
