<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Scoring;

use LiveScoreTracker\Domain\Config\TieBreaker;

/**
 * Overall ranking of one category: total of the event scores, then tie-breaks (spec § 9.5, § 9.6).
 */
final class CategoryRanker
{
    /**
     * @param list<Competitor>  $competitors children of the category
     * @param list<EventScores> $events      scores of every event of the category's level group
     * @param list<TieBreaker>  $tieBreakers criteria applied in this order
     *
     * @return list<RankedCompetitor> present children by rank, then absent children
     */
    public static function rank(array $competitors, array $events, array $tieBreakers): array
    {
        /** @var array<string, array{competitor: Competitor, total: int, first: int, top3: int, complete: bool, events: array<string, EntryScore|null>}> $rows */
        $rows = [];
        foreach ($competitors as $competitor) {
            $row = ['competitor' => $competitor, 'total' => 0, 'first' => 0, 'top3' => 0, 'complete' => true, 'events' => []];
            foreach ($events as $event) {
                $entry = $event->entry($competitor->bib);
                $row['events'][$event->variantId] = $entry;
                if ($entry === null || $entry->status === EntryStatus::Pending) {
                    $row['complete'] = false;
                    continue;
                }
                $row['total'] += $entry->score ?? 0;
                // Only real performances count as places (a foul or DNF is never a "1st place").
                if ($entry->status === EntryStatus::Valid && $entry->rank !== null) {
                    $row['first'] += $entry->rank === 1 ? 1 : 0;
                    $row['top3'] += $entry->rank <= 3 ? 1 : 0;
                }
            }
            $rows[$competitor->bib] = $row;
        }

        $present = array_filter($rows, static fn(array $row): bool => $row['competitor']->present);
        $totals = array_map(static fn(array $row): int => $row['total'], $present);

        // Group children with equal totals, best total first.
        $groups = [];
        foreach ($totals as $bib => $total) {
            $groups[$total][] = (string) $bib;
        }
        krsort($groups, SORT_NUMERIC);

        $decidedBy = [];
        $ordered = [];
        foreach ($groups as $members) {
            foreach (self::separate($members, $tieBreakers, $rows, $decidedBy) as $subgroup) {
                $ordered[] = $subgroup;
            }
        }

        $result = [];
        $position = 1;
        foreach ($ordered as $subgroup) {
            $tied = \count($subgroup) > 1;
            foreach (self::byBib($subgroup) as $bib) {
                $result[] = self::line($rows[$bib], $position, $decidedBy[$bib] ?? null, $tied);
            }
            $position += \count($subgroup);
        }

        foreach ($rows as $row) {
            if (!$row['competitor']->present) {
                $result[] = self::line($row, null, null, false);
            }
        }

        return $result;
    }

    /**
     * Splits children with equal totals using the criteria in order.
     * Returns ordered subgroups; a subgroup of several children is a tie nobody could break.
     *
     * @param list<string>                                                $members
     * @param list<TieBreaker>                                            $criteria
     * @param array<string, array{competitor: Competitor, first: int, top3: int}> $rows
     * @param array<string, TieBreaker>                                   $decidedBy filled in
     *
     * @return list<list<string>>
     */
    private static function separate(array $members, array $criteria, array $rows, array &$decidedBy): array
    {
        if (\count($members) < 2 || $criteria === []) {
            return [$members];
        }

        $criterion = $criteria[0];
        $rest = \array_slice($criteria, 1);

        $keys = [];
        foreach ($members as $bib) {
            $key = self::key($criterion, $rows[$bib]);
            if ($key === null) {
                // Criterion unusable for this group (e.g. a birth date is missing): try the next one.
                return self::separate($members, $rest, $rows, $decidedBy);
            }
            $keys[$bib] = $key;
        }

        $subgroups = [];
        foreach ($keys as $bib => $key) {
            $subgroups[$key][] = (string) $bib;
        }
        if (\count($subgroups) === 1) {
            return self::separate($members, $rest, $rows, $decidedBy);
        }
        krsort($subgroups, SORT_NUMERIC);

        $ordered = [];
        foreach ($subgroups as $subgroup) {
            foreach ($subgroup as $bib) {
                $decidedBy[$bib] ??= $criterion;
            }
            foreach (self::separate($subgroup, $rest, $rows, $decidedBy) as $part) {
                $ordered[] = $part;
            }
        }

        return $ordered;
    }

    /**
     * Value to maximise for a criterion, or null when it cannot be applied.
     *
     * @param array{competitor: Competitor, first: int, top3: int} $row
     */
    private static function key(TieBreaker $criterion, array $row): ?int
    {
        return match ($criterion) {
            TieBreaker::FirstPlaces => $row['first'],
            TieBreaker::Top3Places => $row['top3'],
            // The youngest wins: the most recent birth date is the largest timestamp.
            TieBreaker::Youngest => $row['competitor']->birthDate?->getTimestamp(),
        };
    }

    /**
     * @param array{competitor: Competitor, total: int, first: int, top3: int, complete: bool, events: array<string, EntryScore|null>} $row
     */
    private static function line(array $row, ?int $rank, ?TieBreaker $decidedBy, bool $tied): RankedCompetitor
    {
        return new RankedCompetitor(
            bib: $row['competitor']->bib,
            rank: $rank,
            total: $row['total'],
            firstPlaces: $row['first'],
            top3Places: $row['top3'],
            decidedBy: $decidedBy,
            tied: $tied,
            complete: $row['complete'],
            present: $row['competitor']->present,
            events: $row['events'],
        );
    }

    /**
     * @param list<string> $bibs
     *
     * @return list<string>
     */
    private static function byBib(array $bibs): array
    {
        usort($bibs, static fn(string $a, string $b): int => strnatcmp($a, $b));

        return $bibs;
    }
}
