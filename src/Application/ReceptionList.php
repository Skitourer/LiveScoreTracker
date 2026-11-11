<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use Collator;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\TextCleaner;

/**
 * The printed reception list (spec § 6.1): registered children sorted by first
 * name, so that each child finds their bib, grouped by initial letter.
 */
final class ReceptionList
{
    /**
     * @param list<Registration> $registrations
     *
     * @return array<string, list<Registration>> initial letter => children
     */
    public static function byFirstName(array $registrations, string $locale): array
    {
        $collator = class_exists(Collator::class) ? new Collator($locale) : null;
        $key = static fn(Registration $r): string => $r->child->firstName . ' ' . $r->child->lastName;
        usort($registrations, static function (Registration $a, Registration $b) use ($collator, $key): int {
            $order = $collator?->compare($key($a), $key($b));
            if (!\is_int($order)) {
                // Without the intl extension: compare without accents or case.
                $order = strcmp(TextCleaner::key($key($a)), TextCleaner::key($key($b)));
            }

            // Same names (twins with the same first name are rare but possible): by bib.
            return $order !== 0 ? $order : ($a->bib ?? 0) <=> ($b->bib ?? 0);
        });

        $groups = [];
        foreach ($registrations as $registration) {
            $initial = mb_strtoupper(mb_substr(TextCleaner::key($registration->child->firstName), 0, 1)) ?: '#';
            $groups[$initial][] = $registration;
        }

        return $groups;
    }
}
