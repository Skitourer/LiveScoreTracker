<?php

declare(strict_types=1);

namespace LiveScoreTracker\Http;

/**
 * How far the current visitor is in the login process.
 */
enum AuthState: string
{
    case Anonymous = 'anonymous';
    /** Password OK, the admin must type a 2FA code. */
    case TwoFactorChallenge = 'two_factor_challenge';
    /** Password OK, the admin has no 2FA yet and must set it up. */
    case TwoFactorSetup = 'two_factor_setup';
    /** Shared referee account: the referee must first say who they are. */
    case RefereeNameRequired = 'referee_name_required';
    /** Logged in with a temporary password that must be changed first. */
    case MustChangePassword = 'must_change_password';
    case Authenticated = 'authenticated';
}
