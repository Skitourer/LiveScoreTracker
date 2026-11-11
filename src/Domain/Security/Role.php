<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * Account roles (spec § 3.1). Participants log in with bib + birth date
 * and have no account: they are handled separately.
 */
enum Role: string
{
    case Admin = 'admin';
    case Organizer = 'organizer';
    case Referee = 'referee';

    /**
     * Individual accounts have a personal password; the referee account is shared.
     */
    public function isIndividual(): bool
    {
        return $this !== self::Referee;
    }

    public function requiresTwoFactor(): bool
    {
        return $this === self::Admin;
    }
}
