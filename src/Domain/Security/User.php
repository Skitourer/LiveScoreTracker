<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * An account (admin, organizer or the shared referee account).
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly ?string $email,
        public readonly Role $role,
        public readonly string $passwordHash,
        public readonly bool $mustChangePassword,
        public readonly ?string $totpSecret,
        public readonly bool $totpEnabled,
        public readonly int $sessionVersion,
        public readonly bool $disabled,
        public readonly ?string $lastLoginAt,
    ) {}

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
}
