<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

use DateTimeImmutable;

/**
 * Cleaning of typed or imported values (spec § 5.7): spaces, case, accents,
 * phone numbers, dates, yes/no answers.
 */
final class TextCleaner
{
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ñ' => 'n',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ý' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
    ];

    private const YES = ['oui', 'o', 'yes', 'y', 'x', '1', 'true', 'vrai', 'ok'];

    /**
     * Trims and collapses every kind of whitespace (including non-breaking spaces).
     */
    public static function spaces(string $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value) ?? $value);
    }

    /**
     * Comparison key: lower case, no accent, letters and digits only.
     */
    public static function key(string $value): string
    {
        $value = strtr(mb_strtolower(self::spaces($value)), self::ACCENTS);

        return preg_replace('/[^a-z0-9]/', '', $value) ?? $value;
    }

    /**
     * "jean-PIERRE " → "Jean-Pierre".
     */
    public static function firstName(string $value): string
    {
        return mb_convert_case(mb_strtolower(self::spaces($value)), MB_CASE_TITLE);
    }

    /**
     * "de la fontaine" → "DE LA FONTAINE".
     */
    public static function lastName(string $value): string
    {
        return mb_strtoupper(self::spaces($value));
    }

    public static function email(string $value): string
    {
        return mb_strtolower(self::spaces($value));
    }

    public static function isEmail(string $value): bool
    {
        return mb_strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Normalizes a phone number:
     * "0612345678" → "06 12 34 56 78" (leading zero restored if a spreadsheet lost it),
     * "0033 6 12…" or "+33 (0)6 12…" → "+33 6 12 34 56 78", other countries → "+" and digits.
     */
    public static function phone(string $value): string
    {
        $value = self::spaces($value);
        $international = str_starts_with($value, '+') || str_starts_with($value, '00');
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if (!$international) {
            if (\strlen($digits) === 9 && !str_starts_with($digits, '0')) {
                $digits = '0' . $digits; // leading zero lost by a spreadsheet
            }

            return \strlen($digits) === 10 ? trim(chunk_split($digits, 2, ' ')) : $digits;
        }

        if (str_starts_with($value, '00')) {
            $digits = substr($digits, 2);
        }
        // French number written "+33 (0)6…": drop the national zero.
        if (str_starts_with($digits, '330') && \strlen($digits) === 12) {
            $digits = '33' . substr($digits, 3);
        }
        if (str_starts_with($digits, '33') && \strlen($digits) === 11) {
            return '+33 ' . substr($digits, 2, 1) . ' ' . trim(chunk_split(substr($digits, 3), 2, ' '));
        }

        return '+' . $digits;
    }

    /**
     * A French number (10 digits starting with 0) or an international one (+ and 8 to 15 digits;
     * exactly 9 digits after +33).
     */
    public static function isPhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (!str_starts_with(trim($value), '+')) {
            return \strlen($digits) === 10 && str_starts_with($digits, '0');
        }
        if (str_starts_with($digits, '33')) {
            return \strlen($digits) === 11 && $digits[2] !== '0';
        }

        return \strlen($digits) >= 8 && \strlen($digits) <= 15;
    }

    public static function yes(string $value): bool
    {
        return \in_array(self::key($value), self::YES, true);
    }

    /**
     * Accepts 2016-05-31, 31/05/2016, 31-05-2016, 31.05.2016 and 31/05/16.
     *
     * @return string|null 'YYYY-MM-DD', or null when not a real date
     */
    public static function date(string $value): ?string
    {
        $value = self::spaces($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $value, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', $value, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year < 100) {
                $year += 2000;
            }
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? \sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * 'YYYY-MM-DD' → 'DD/MM/YYYY' for display.
     */
    public static function displayDate(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('d/m/Y');
    }
}
