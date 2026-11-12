<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;

/**
 * Checks that compare a result with the others (spec § 10.1), made on the
 * control board once enough results are known:
 *
 * - far from the median: a child's retained result is more than twice, or
 *   less than half, the median of the other children of the category;
 * - wrong distance probable (timed events whose variants are different
 *   distances, written in their labels, e.g. sprint 30 m / 40 m): the
 *   retained time is what a typical child of the category would do on the
 *   other distance (median of the category × ratio of the distances).
 *
 * Only times and distances are compared, and only when at least
 * MIN_RESULTS other results are available.
 */
final class GlobalChecks
{
    public const FAR_FROM_MEDIAN = 'far_from_median';
    public const WRONG_DISTANCE = 'wrong_distance';

    public const MIN_RESULTS = 5;
    public const MEDIAN_FACTOR = 2;

    /**
     * @param list<Variant>                                                        $variants every event
     * @param list<array{attempts: list<StoredAttempt>, category: string}> $children current attempts of each child, with their category
     *
     * @return array<int, list<string>> attempt id => check codes
     */
    public static function check(array $variants, array $children): array
    {
        /** @var array<string, Variant> $byId */
        $byId = [];
        foreach ($variants as $variant) {
            $byId[$variant->id()] = $variant;
        }

        // Retained result of each child on each event: [variant id => list of [attempt, category]].
        $best = [];
        foreach ($children as $child) {
            $byVariant = [];
            foreach ($child['attempts'] as $attempt) {
                $variant = $byId[$attempt->variantId] ?? null;
                if ($variant === null || !self::comparable($variant) || $attempt->result() === null) {
                    continue;
                }
                $current = $byVariant[$attempt->variantId] ?? null;
                if ($current === null || self::better($variant, (int) $attempt->result(), (int) $current->result())) {
                    $byVariant[$attempt->variantId] = $attempt;
                }
            }
            foreach ($byVariant as $variantId => $attempt) {
                $best[$variantId][] = ['attempt' => $attempt, 'category' => $child['category']];
            }
        }

        $flags = [];
        foreach ($best as $variantId => $lines) {
            $variant = $byId[$variantId];
            foreach ($lines as $line) {
                $result = (int) $line['attempt']->result();
                $others = [];
                foreach ($lines as $other) {
                    if ($other['attempt']->id !== $line['attempt']->id && $other['category'] === $line['category']) {
                        $others[] = (int) $other['attempt']->result();
                    }
                }
                if (\count($others) >= self::MIN_RESULTS) {
                    $median = self::median($others);
                    if ($median > 0 && ($result > self::MEDIAN_FACTOR * $median || $result * self::MEDIAN_FACTOR < $median)) {
                        $flags[$line['attempt']->id][] = self::FAR_FROM_MEDIAN;
                    }
                }
                if (self::wrongDistance($variant, $line, $lines, $byId)) {
                    $flags[$line['attempt']->id][] = self::WRONG_DISTANCE;
                }
            }
        }

        return $flags;
    }

    /**
     * The other distance of the workshop, run by mistake: the time is at least
     * what a typical child of the category would do on it (median of the
     * category scaled by the ratio of the distances).
     *
     * @param array{attempt: StoredAttempt, category: string}       $line
     * @param list<array{attempt: StoredAttempt, category: string}> $lines results of the same event
     * @param array<string, Variant>                                                $byId
     */
    private static function wrongDistance(Variant $variant, array $line, array $lines, array $byId): bool
    {
        $length = self::length($variant);
        if ($variant->measure !== Measure::Time || $length === null) {
            return false;
        }
        $others = [];
        foreach ($lines as $other) {
            if ($other['attempt']->id !== $line['attempt']->id && $other['category'] === $line['category']) {
                $others[] = (int) $other['attempt']->result();
            }
        }
        if (\count($others) < self::MIN_RESULTS) {
            return false;
        }
        $median = self::median($others);
        $result = (int) $line['attempt']->result();
        foreach ($byId as $other) {
            $otherLength = self::length($other);
            if ($other->workshopId !== $variant->workshopId || $other->measure !== Measure::Time || $otherLength === null || $otherLength === $length) {
                continue;
            }
            $expected = $median * $otherLength / $length;
            if (($otherLength > $length && $result >= $expected) || ($otherLength < $length && $result <= $expected)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distance written in the variant's label ("30 m" → 30), if any.
     */
    private static function length(Variant $variant): ?float
    {
        if ($variant->label === null || preg_match('/(\d+(?:[.,]\d+)?)\s*m\b/u', $variant->label, $m) !== 1) {
            return null;
        }
        $length = (float) str_replace(',', '.', $m[1]);

        return $length > 0 ? $length : null;
    }

    private static function comparable(Variant $variant): bool
    {
        return $variant->measure === Measure::Time || $variant->measure === Measure::Distance;
    }

    private static function better(Variant $variant, int $candidate, int $current): bool
    {
        return $variant->better === Better::Lower ? $candidate < $current : $candidate > $current;
    }

    /**
     * @param non-empty-list<int>|list<int> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
