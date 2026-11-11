<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * Password rules (spec § 19.1): 12 characters minimum for individual accounts.
 * The shared referee password is typed on many phones, so it may be shorter.
 */
final class PasswordPolicy
{
    public const INDIVIDUAL_MIN_LENGTH = 12;
    public const SHARED_MIN_LENGTH = 8;
    public const MAX_LENGTH = 200;

    /**
     * @return string|null translation key of the problem, or null when the password is acceptable
     */
    public static function check(string $password, Role $role): ?string
    {
        $length = mb_strlen($password);
        $min = $role->isIndividual() ? self::INDIVIDUAL_MIN_LENGTH : self::SHARED_MIN_LENGTH;

        if ($length < $min) {
            return $role->isIndividual() ? 'password.too_short_individual' : 'password.too_short_shared';
        }
        if ($length > self::MAX_LENGTH) {
            return 'password.too_long';
        }
        if (trim($password) !== $password) {
            return 'password.spaces_around';
        }

        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
    }
}
