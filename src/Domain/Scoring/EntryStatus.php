<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * Situation of a child on one event (spec § 9.3).
 */
enum EntryStatus: string
{
    /** At least one valid attempt: scored on the points scale. */
    case Valid = 'valid';
    /** Only foul attempts: gets foul_score. */
    case Foul = 'foul';
    /** Present, no attempt when the competition is closed: gets dnf_score. */
    case Dnf = 'dnf';
    /** Present, no attempt yet, competition still running: no score yet. */
    case Pending = 'pending';
    /** Absent child: excluded from scoring and rankings. */
    case Absent = 'absent';
}
