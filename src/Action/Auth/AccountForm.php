<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Infrastructure\Persistence\UserRepository;

/**
 * Validation of account fields, shared by the setup and admin screens.
 */
final class AccountForm
{
    /**
     * @param array{username: string, display_name: string, email: string} $input
     * @param int|null                                                       $existingId account being edited (its username is not checked)
     * @param bool                                                           $emailRequired
     *
     * @return array<string, array<string, int>> translation key => parameters
     */
    public static function validate(array $input, UserRepository $users, ?int $existingId, bool $emailRequired = true): array
    {
        $errors = [];
        if ($existingId === null) {
            if (preg_match('/^[a-z0-9._-]{3,32}$/', $input['username']) !== 1) {
                $errors['account.username_invalid'] = [];
            } elseif ($users->usernameExists($input['username'])) {
                $errors['account.username_taken'] = [];
            }
        }
        $length = mb_strlen($input['display_name']);
        if ($length < 2 || $length > 120) {
            $errors['account.display_name_invalid'] = [];
        }
        if ($input['email'] === '') {
            if ($emailRequired) {
                $errors['account.email_required'] = [];
            }
        } elseif (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false || mb_strlen($input['email']) > 190) {
            $errors['account.email_invalid'] = [];
        }

        return $errors;
    }
}
