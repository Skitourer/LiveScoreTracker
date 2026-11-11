<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * How a child was registered.
 */
enum RegistrationSource: string
{
    /** Public online form (step 11). */
    case Online = 'online';
    /** Typed in by an admin or organizer before the competition. */
    case Manual = 'manual';
    /** Added on the day, while the competition is running (spec § 5.8). */
    case OnSite = 'on_site';
    /** Spreadsheet import (spec § 5.7). */
    case Import = 'import';
}
