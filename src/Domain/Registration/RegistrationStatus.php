<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * Life cycle of a registration (spec § 5.3):
 * pending_verification → registered | waitlisted → registered | withdrawn | expired;
 * a withdrawn child can be registered again (or put on the waiting list).
 */
enum RegistrationStatus: string
{
    case PendingVerification = 'pending_verification';
    case Registered = 'registered';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    /**
     * Statuses an admin may move a registration to from this one.
     *
     * @return list<self>
     */
    public function adminTransitions(): array
    {
        return match ($this) {
            self::PendingVerification => [self::Registered, self::Waitlisted, self::Withdrawn],
            self::Waitlisted => [self::Registered, self::Withdrawn],
            self::Registered => [self::Withdrawn],
            self::Withdrawn => [self::Registered, self::Waitlisted],
            self::Expired => [],
        };
    }
}
