<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Scoring;

use DateTimeImmutable;
use LiveScoreTracker\Domain\Config\TieBreaker;
use LiveScoreTracker\Domain\Scoring\CategoryRanker;
use LiveScoreTracker\Domain\Scoring\Competitor;
use LiveScoreTracker\Domain\Scoring\EntryScore;
use LiveScoreTracker\Domain\Scoring\EntryStatus;
use LiveScoreTracker\Domain\Scoring\EventScores;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;
use PHPUnit\Framework\TestCase;

final class CategoryRankerTest extends TestCase
{
    private const CRITERIA = [TieBreaker::FirstPlaces, TieBreaker::Top3Places, TieBreaker::Youngest];

    public function testRanksByTotal(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1'), new Competitor('2'), new Competitor('3')],
            [
                self::event('a', ['1' => [900, 2], '2' => [1000, 1], '3' => [100, 3]]),
                self::event('b', ['1' => [1000, 1], '2' => [500, 2], '3' => [100, 3]]),
            ],
            self::CRITERIA,
        );

        self::assertSame(['1' => 1, '2' => 2, '3' => 3], self::ranks($ranking));
        self::assertSame(1900, $ranking[0]->total);
        self::assertNull($ranking[0]->decidedBy);
        self::assertTrue($ranking[0]->complete);
    }

    public function testFirstPlacesBreakATie(): void
    {
        // Both total 1100; child 2 has one 1st place, child 1 none.
        $ranking = CategoryRanker::rank(
            [new Competitor('1'), new Competitor('2'), new Competitor('3')],
            [
                self::event('a', ['1' => [600, 2], '2' => [1000, 1], '3' => [100, 3]]),
                self::event('b', ['1' => [500, 2], '2' => [100, 3], '3' => [900, 1]]),
            ],
            self::CRITERIA,
        );

        self::assertSame(['2' => 1, '1' => 2, '3' => 3], self::ranks($ranking));
        self::assertSame(TieBreaker::FirstPlaces, $ranking[0]->decidedBy);
        self::assertSame(TieBreaker::FirstPlaces, $ranking[1]->decidedBy);
        self::assertFalse($ranking[0]->tied);
    }

    public function testFallsBackToTop3ThenYoungest(): void
    {
        $older = new DateTimeImmutable('2017-01-10');
        $younger = new DateTimeImmutable('2017-09-30');

        // Same total, same 1st places (0), same top-3 places (1): the youngest wins.
        $ranking = CategoryRanker::rank(
            [new Competitor('1', birthDate: $older), new Competitor('2', birthDate: $younger), new Competitor('3')],
            [
                self::event('a', ['3' => [1000, 1], '1' => [500, 2], '2' => [400, 4]]),
                self::event('b', ['3' => [1000, 1], '2' => [500, 2], '1' => [400, 4]]),
            ],
            self::CRITERIA,
        );

        self::assertSame(['3', '2', '1'], array_map(static fn(RankedCompetitor $r): string => $r->bib, $ranking));
        self::assertSame(TieBreaker::Youngest, $ranking[1]->decidedBy);
        self::assertSame(2, $ranking[1]->rank);
        self::assertSame(3, $ranking[2]->rank);
    }

    public function testTop3PlacesBreakATie(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1'), new Competitor('2'), new Competitor('3'), new Competitor('4')],
            [
                self::event('a', ['4' => [1000, 1], '1' => [700, 3], '3' => [800, 2], '2' => [600, 4]]),
                self::event('b', ['4' => [1000, 1], '2' => [700, 2], '3' => [500, 3], '1' => [600, 4]]),
            ],
            self::CRITERIA,
        );

        // 1 and 2 both total 1300 with no 1st place; child 1 has a top-3 place on event a, child 2 on event b: still equal.
        // Child 3 total 1300 too, with two top-3 places → ahead.
        self::assertSame(['4', '3', '1', '2'], array_map(static fn(RankedCompetitor $r): string => $r->bib, $ranking));
        self::assertSame(TieBreaker::Top3Places, $ranking[1]->decidedBy);
        self::assertTrue($ranking[2]->tied);
        self::assertSame(3, $ranking[2]->rank);
        self::assertSame(3, $ranking[3]->rank);
    }

    public function testUnbreakableTieIsFlaggedForTheAdmin(): void
    {
        // Twins: same scores, same birth date.
        $date = new DateTimeImmutable('2016-05-05');
        $ranking = CategoryRanker::rank(
            [new Competitor('1', birthDate: $date), new Competitor('2', birthDate: $date)],
            [self::event('a', ['1' => [1000, 1], '2' => [1000, 1]])],
            self::CRITERIA,
        );

        self::assertSame([1, 1], [$ranking[0]->rank, $ranking[1]->rank]);
        self::assertTrue($ranking[0]->tied);
        self::assertTrue($ranking[1]->tied);
    }

    public function testMissingBirthDateSkipsTheYoungestCriterion(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1', birthDate: new DateTimeImmutable('2016-01-01')), new Competitor('2')],
            [self::event('a', ['1' => [500, 1], '2' => [500, 1]])],
            self::CRITERIA,
        );

        self::assertTrue($ranking[0]->tied);
        self::assertNull($ranking[0]->decidedBy);
    }

    public function testCriteriaOrderIsConfigurable(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1', birthDate: new DateTimeImmutable('2016-01-01')), new Competitor('2', birthDate: new DateTimeImmutable('2015-01-01'))],
            [
                self::event('a', ['1' => [1000, 1], '2' => [500, 2]]),
                self::event('b', ['2' => [1000, 1], '1' => [500, 2]]),
            ],
            [TieBreaker::Youngest],
        );

        self::assertSame('1', $ranking[0]->bib);
        self::assertSame(TieBreaker::Youngest, $ranking[0]->decidedBy);
    }

    public function testFoulsAndDnfNeverCountAsPlaces(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1'), new Competitor('2')],
            [new EventScores('a', [
                new EntryScore('1', EntryStatus::Foul, null, 1, 1),
                new EntryScore('2', EntryStatus::Dnf, null, 0, 2),
            ], null, null)],
            self::CRITERIA,
        );

        self::assertSame(0, $ranking[0]->firstPlaces);
        self::assertSame(1, $ranking[0]->total);
    }

    public function testPendingEventsAndAbsentChildren(): void
    {
        $ranking = CategoryRanker::rank(
            [new Competitor('1'), new Competitor('2'), new Competitor('9', present: false)],
            [
                self::event('a', ['1' => [1000, 1], '2' => [100, 2]]),
                new EventScores('b', [new EntryScore('1', EntryStatus::Pending, null, null, null)], null, null),
            ],
            self::CRITERIA,
        );

        self::assertSame(['1', '2', '9'], array_map(static fn(RankedCompetitor $r): string => $r->bib, $ranking));
        self::assertFalse($ranking[0]->complete);
        self::assertFalse($ranking[1]->complete); // no entry at all on event b yet
        self::assertNull($ranking[2]->rank);
        self::assertFalse($ranking[2]->present);
    }

    /**
     * @param array<array-key, array{int, int}> $scores bib => [points, rank]
     */
    private static function event(string $id, array $scores): EventScores
    {
        $entries = [];
        foreach ($scores as $bib => [$points, $rank]) {
            $entries[] = new EntryScore((string) $bib, EntryStatus::Valid, 0, $points, $rank);
        }

        return new EventScores($id, $entries, null, null);
    }

    /**
     * @param list<RankedCompetitor> $ranking
     *
     * @return array<string, int|null>
     */
    private static function ranks(array $ranking): array
    {
        $ranks = [];
        foreach ($ranking as $line) {
            $ranks[$line->bib] = $line->rank;
        }

        return $ranks;
    }
}
