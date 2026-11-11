<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Measurement;

use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\TimeFormat;

/**
 * Turns what a referee types (digits only) into a value in storage units (spec § 7.3).
 *
 * - time `ss.cc`:   "1225"  → 1225 hundredths (12.25 s)
 * - time `m:ss.cc`: "12324" → 8324 hundredths (1 min 23.24 s); seconds must be below 60
 * - distance:       "1234"  → 1234 cm (12.34 m)
 * - score/counter:  "22"    → 22 points
 */
final class EntryParser
{
    /**
     * @return int|null null when the entry is not a valid value
     */
    public static function parse(string $input, Measure $measure, ?TimeFormat $timeFormat = null): ?int
    {
        $digits = trim($input);
        if ($digits === '' || !ctype_digit($digits) || \strlen($digits) > 7) {
            return null;
        }
        $number = (int) $digits;

        if ($measure !== Measure::Time || $timeFormat !== TimeFormat::Minutes) {
            return $number;
        }

        $hundredths = $number % 100;
        $seconds = intdiv($number, 100) % 100;
        $minutes = intdiv($number, 10000);
        if ($seconds >= 60) {
            return null;
        }

        return ($minutes * 60 + $seconds) * 100 + $hundredths;
    }

    /**
     * The digits a referee would type for a stored value (to correct it): the reverse of parse().
     */
    public static function toDigits(int $value, Measure $measure, ?TimeFormat $timeFormat = null): string
    {
        if ($measure !== Measure::Time || $timeFormat !== TimeFormat::Minutes || $value < 6000) {
            return (string) $value;
        }
        $minutes = intdiv($value, 6000);

        return $minutes . str_pad((string) ($value % 6000), 4, '0', STR_PAD_LEFT);
    }
}
