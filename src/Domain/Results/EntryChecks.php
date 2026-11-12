<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

/**
 * Non-blocking checks on a new or corrected attempt (spec § 10.1). Each
 * warning must be confirmed by the referee and marks the result "to check".
 *
 * Blocking checks (unknown bib, event outside the child's level group,
 * competition not running) are made before the entry form is shown.
 */
final class EntryChecks
{
    public const NOT_PRESENT = 'not_present';
    public const ATTEMPTS_USED = 'attempts_used';
    public const IMPLAUSIBLE = 'implausible';
    public const ABOVE_MAX = 'above_max';
    public const DUPLICATE = 'duplicate';
    public const TOO_MANY_SHOTS = 'too_many_shots';

    /** Same value for the same child within this delay: probably entered twice. */
    public const DUPLICATE_SECONDS = 120;

    /**
     * @param int|null $measured  value in storage units, null for a foul
     * @param bool     $isNew     false for a correction (the attempt count does not change)
     * @param int      $now       Unix time
     *
     * @return list<string> warning codes
     */
    public static function check(AttemptProgress $progress, ?int $measured, bool $present, bool $isNew, int $now): array
    {
        $warnings = [];
        if (!$present) {
            $warnings[] = self::NOT_PRESENT;
        }
        if ($isNew && $progress->isFinished()) {
            $warnings[] = self::ATTEMPTS_USED;
        }
        if ($measured !== null) {
            $variant = $progress->variant;
            if (!$variant->isPlausible($measured)) {
                $warnings[] = self::IMPLAUSIBLE;
            }
            if ($variant->maxValue !== null && $measured > $variant->maxValue) {
                $warnings[] = self::ABOVE_MAX;
            }
            if ($isNew) {
                foreach ($progress->attempts as $attempt) {
                    $age = $now - (int) strtotime($attempt->enteredAt . ' UTC');
                    if ($attempt->measured === $measured && $age >= 0 && $age <= self::DUPLICATE_SECONDS) {
                        $warnings[] = self::DUPLICATE;
                        break;
                    }
                }
            }
        }

        return $warnings;
    }
}
