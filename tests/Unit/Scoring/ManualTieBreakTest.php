<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Scoring;

use LiveScoreTracker\Domain\Scoring\ManualTieBreak;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;
use PHPUnit\Framework\TestCase;

/**
 * Ties ordered by hand by the admin (spec § 9.6).
 */
final class ManualTieBreakTest extends TestCase
{
    public function testADecisionOrdersTheTiedChildren(): void
    {
        $ranking = [self::line('4', 1, 2000), self::line('1', 2, 1500, true), self::line('2', 2, 1500, true), self::line('3', 4, 900)];
        self::assertCount(1, ManualTieBreak::unresolved($ranking));

        $decided = ManualTieBreak::apply($ranking, [['2', '1']]);

        self::assertSame([['4', 1, false], ['2', 2, true], ['1', 3, true], ['3', 4, false]], array_map(
            static fn(RankedCompetitor $line): array => [$line->bib, $line->rank, $line->manual],
            $decided,
        ));
        self::assertFalse($decided[1]->tied);
        self::assertSame([], ManualTieBreak::unresolved($decided));
    }

    public function testADecisionForOtherChildrenIsIgnored(): void
    {
        // The tie is now between 1, 2 and 3: the old decision about 1 and 2 no longer applies.
        $ranking = [self::line('1', 1, 1500, true), self::line('2', 1, 1500, true), self::line('3', 1, 1500, true)];
        $result = ManualTieBreak::apply($ranking, [['2', '1']]);

        self::assertSame([1, 1, 1], array_map(static fn(RankedCompetitor $line): ?int => $line->rank, $result));
        self::assertCount(1, ManualTieBreak::unresolved($result));
    }

    public function testTwoSeparateTiesAreKeptApart(): void
    {
        $ranking = [self::line('1', 1, 1500, true), self::line('2', 1, 1500, true), self::line('3', 3, 900, true), self::line('4', 3, 900, true)];

        self::assertCount(2, ManualTieBreak::unresolved($ranking));
        self::assertCount(1, ManualTieBreak::unresolved(ManualTieBreak::apply($ranking, [['4', '3']])));
    }

    private static function line(string $bib, int $rank, int $total, bool $tied = false): RankedCompetitor
    {
        return new RankedCompetitor($bib, $rank, $total, 0, 0, null, $tied, true, true, []);
    }
}
