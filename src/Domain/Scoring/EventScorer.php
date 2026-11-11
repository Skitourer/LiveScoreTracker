<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\Variant;

/**
 * Turns the raw results of one event, for one category, into points (spec § 9.1 – 9.4).
 *
 * Scores are always recomputed from raw results (spec § 9.7): this class keeps no state.
 */
final class EventScorer
{
    /**
     * @param list<EventEntry> $entries children of ONE category on ONE event
     * @param bool             $closed  true once the competition is closed: missing results become DNF
     */
    public static function score(Variant $variant, array $entries, bool $closed = false): EventScores
    {
        $rules = $variant->scoring;

        // 1. Best valid result of each present child (§ 9.1).
        $bests = [];
        foreach ($entries as $entry) {
            if ($entry->present) {
                $best = self::bestResult($entry->attempts, $variant->better);
                if ($best !== null) {
                    $bests[$entry->bib] = $best;
                }
            }
        }

        // 2. Scale bounds: only valid results count; absents, fouls and DNF never do.
        $best = $worst = null;
        if ($bests !== []) {
            $best = $variant->better === Better::Lower ? min($bests) : max($bests);
            $worst = $variant->better === Better::Lower ? max($bests) : min($bests);
        }

        // 3. Status and points of each child (§ 9.2, § 9.3).
        $scores = [];
        foreach ($entries as $entry) {
            if (!$entry->present) {
                $scores[$entry->bib] = [EntryStatus::Absent, null, null];
            } elseif (isset($bests[$entry->bib], $best, $worst)) {
                $value = $bests[$entry->bib];
                $scores[$entry->bib] = [EntryStatus::Valid, $value, self::points($value, $best, $worst, $rules)];
            } elseif ($entry->attempts !== []) {
                $scores[$entry->bib] = [EntryStatus::Foul, null, $rules->foulScore];
            } elseif ($closed) {
                $scores[$entry->bib] = [EntryStatus::Dnf, null, $rules->dnfScore];
            } else {
                $scores[$entry->bib] = [EntryStatus::Pending, null, null];
            }
        }

        // 4. Rank on the event: by points, ties share the same rank (§ 9.4).
        $points = [];
        foreach ($scores as $bib => [, , $score]) {
            if ($score !== null) {
                $points[(string) $bib] = $score;
            }
        }
        $ranks = Ranks::competition($points);

        $result = [];
        foreach (self::sortedBibs($scores, $points) as $bib) {
            [$status, $value, $score] = $scores[$bib];
            $result[] = new EntryScore($bib, $status, $value, $score, $ranks[$bib] ?? null);
        }

        return new EventScores($variant->id(), $result, $best, $worst);
    }

    /**
     * Points for a valid result, rounded to the nearest whole number (half up).
     *
     *   progression = (v − worst) / (best − worst)   — between 0 and 1 whatever the direction
     *   score       = floor + progression × (max_score − floor)
     *
     * Computed with integers only, so rounding never depends on floating-point error.
     * A single valid result, or all valid results equal, gives max_score to everyone.
     */
    public static function points(int $value, int $best, int $worst, ScoringRules $rules): int
    {
        if ($best === $worst) {
            return $rules->maxScore;
        }

        $numerator = ($value - $worst) * ($rules->maxScore - $rules->floor);
        $denominator = $best - $worst;
        if ($denominator < 0) {
            $numerator = -$numerator;
            $denominator = -$denominator;
        }

        return $rules->floor + intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * Best retained result among the valid attempts (§ 7.4), or null if all are fouls.
     *
     * @param list<Attempt> $attempts
     */
    public static function bestResult(array $attempts, Better $better): ?int
    {
        $best = null;
        foreach ($attempts as $attempt) {
            $result = $attempt->result();
            if ($result !== null && ($best === null || $better->isBetter($result, $best))) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * Scored children first (best first, then by bib), then pending, then absent.
     *
     * @param array<string, array{0: EntryStatus, 1: int|null, 2: int|null}> $scores
     * @param array<string, int>                                             $points
     *
     * @return list<string>
     */
    private static function sortedBibs(array $scores, array $points): array
    {
        $bibs = array_map('strval', array_keys($scores));
        usort($bibs, static function (string $a, string $b) use ($scores, $points): int {
            $order = static fn(string $bib): int => match ($scores[$bib][0]) {
                EntryStatus::Pending => 1,
                EntryStatus::Absent => 2,
                default => 0,
            };

            return [$order($a), -($points[$a] ?? 0), self::bibKey($a)] <=> [$order($b), -($points[$b] ?? 0), self::bibKey($b)];
        });

        return $bibs;
    }

    /**
     * Sorts numeric bibs numerically ("2" before "10").
     *
     * @return array{0: int, 1: string}
     */
    private static function bibKey(string $bib): array
    {
        return [ctype_digit($bib) ? (int) $bib : PHP_INT_MAX, $bib];
    }
}
