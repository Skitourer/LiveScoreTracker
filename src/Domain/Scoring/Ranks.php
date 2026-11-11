<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * Rank helpers.
 */
final class Ranks
{
    /**
     * "Competition" ranking, higher value first: equal values share a rank
     * and the next rank skips accordingly (1, 2, 2, 4).
     *
     * @param array<string, int> $values key => value
     *
     * @return array<string, int> key => rank
     */
    public static function competition(array $values): array
    {
        arsort($values, SORT_NUMERIC);
        $ranks = [];
        $position = 0;
        $rank = 0;
        $previous = null;
        foreach ($values as $key => $value) {
            ++$position;
            if ($value !== $previous) {
                $rank = $position;
                $previous = $value;
            }
            $ranks[(string) $key] = $rank;
        }

        return $ranks;
    }
}
