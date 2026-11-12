<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

/**
 * Ties that no criterion could break are ordered by hand by an admin (spec § 9.6).
 *
 * A decision lists the bibs of a tie, best first. It applies only while exactly
 * these children are still tied: if the results change, the tie (or its
 * absence) is computed again and the old decision is ignored.
 */
final class ManualTieBreak
{
    /**
     * @param list<RankedCompetitor> $ranking   as given by CategoryRanker
     * @param list<list<string>>     $decisions ordered bibs, best first
     *
     * @return list<RankedCompetitor>
     */
    public static function apply(array $ranking, array $decisions): array
    {
        if ($decisions === []) {
            return $ranking;
        }
        $result = [];
        foreach (self::groups($ranking) as $group) {
            $decision = self::decisionFor($group, $decisions);
            if ($decision === null) {
                array_push($result, ...$group);
                continue;
            }
            $byBib = [];
            foreach ($group as $line) {
                $byBib[$line->bib] = $line;
            }
            $rank = (int) $group[0]->rank;
            foreach ($decision as $offset => $bib) {
                $result[] = $byBib[$bib]->decidedByHand($rank + $offset);
            }
        }

        return $result;
    }

    /**
     * Unresolved ties of a ranking: lists of tied lines.
     *
     * @param list<RankedCompetitor> $ranking
     *
     * @return list<non-empty-list<RankedCompetitor>>
     */
    public static function unresolved(array $ranking): array
    {
        return array_values(array_filter(self::groups($ranking), static fn(array $group): bool => \count($group) > 1));
    }

    /**
     * Consecutive lines sharing a rank because of a tie; every other line on its own.
     *
     * @param list<RankedCompetitor> $ranking
     *
     * @return list<non-empty-list<RankedCompetitor>>
     */
    private static function groups(array $ranking): array
    {
        $groups = [];
        $current = [];
        foreach ($ranking as $line) {
            if ($current !== [] && $line->tied && $line->present && $current[0]->tied && $current[0]->rank === $line->rank) {
                $current[] = $line;
                continue;
            }
            if ($current !== []) {
                $groups[] = $current;
            }
            $current = [$line];
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param non-empty-list<RankedCompetitor> $group
     * @param list<list<string>>               $decisions
     *
     * @return list<string>|null
     */
    private static function decisionFor(array $group, array $decisions): ?array
    {
        if (\count($group) < 2) {
            return null;
        }
        $bibs = array_map(static fn(RankedCompetitor $line): string => $line->bib, $group);
        sort($bibs, SORT_NATURAL);
        foreach ($decisions as $decision) {
            $sorted = $decision;
            sort($sorted, SORT_NATURAL);
            if ($sorted === $bibs) {
                return $decision;
            }
        }

        return null;
    }
}
