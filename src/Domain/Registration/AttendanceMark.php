<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * The boxes ticked at the reception (spec § 6.2): the bib handed out on arrival
 * (which makes the child present), then the bib given back and the gift + medal
 * handed out on leaving.
 */
enum AttendanceMark: string
{
    case CheckedIn = 'checked_in';
    case BibReturned = 'bib_returned';
    case GiftGiven = 'gift_given';

    public function column(): string
    {
        return $this->value . '_at';
    }

    public function isExit(): bool
    {
        return $this !== self::CheckedIn;
    }
}
