<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Scoring;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Scoring\Attempt;
use LiveScoreTracker\Domain\Scoring\EntryScore;
use LiveScoreTracker\Domain\Scoring\EntryStatus;
use LiveScoreTracker\Domain\Scoring\EventEntry;
use LiveScoreTracker\Domain\Scoring\EventScorer;
use LiveScoreTracker\Domain\Scoring\EventScores;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventScorerTest extends TestCase
{
    /**
     * Spec annex B, "higher is better" (throw, floor 100): best 68 m, worst 8 m.
     */
    public function testAnnexBThrow(): void
    {
        $scores = EventScorer::score(self::variant(Measure::Distance, Better::Higher, attempts: 2), [
            new EventEntry('A', true, [Attempt::valid(6800)]),
            new EventEntry('B', true, [Attempt::valid(4500), Attempt::foul()]),
            new EventEntry('C', true, [Attempt::valid(800)]),
            new EventEntry('D', true, [Attempt::foul(), Attempt::foul()]),
            new EventEntry('E', true, []),
        ], closed: true);

        self::assertSame(6800, $scores->best);
        self::assertSame(800, $scores->worst);
        self::assertSame(['A' => 1000, 'B' => 655, 'C' => 100, 'D' => 1, 'E' => 0], self::points($scores->entries));
        self::assertSame(EntryStatus::Foul, self::entry($scores, 'D')->status);
        self::assertSame(EntryStatus::Dnf, self::entry($scores, 'E')->status);
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn(EntryScore $e): ?int => $e->rank, $scores->entries));
    }

    /**
     * Spec annex B, "lower is better" (sprint): best 6.20 s, worst 9.80 s, 7.10 s → 775.
     */
    public function testAnnexBSprint(): void
    {
        $scores = EventScorer::score(self::variant(Measure::Time, Better::Lower), [
            new EventEntry('1', true, [Attempt::valid(620)]),
            new EventEntry('2', true, [Attempt::valid(980)]),
            new EventEntry('3', true, [Attempt::valid(710)]),
        ]);

        self::assertSame(['1' => 1000, '3' => 775, '2' => 100], self::points($scores->entries));
    }

    /**
     * Spec annex B, hockey: 18.40 s with a goal (-3.00 s) is retained as 15.40 s.
     */
    public function testModifiersAreAddedToTheMeasuredTime(): void
    {
        $scores = EventScorer::score(self::variant(Measure::Time, Better::Lower, attempts: 2), [
            new EventEntry('1', true, [Attempt::valid(1840, -300), Attempt::valid(1600)]),
            new EventEntry('2', true, [Attempt::valid(2000)]),
        ]);

        self::assertSame(1540, self::entry($scores, '1')->best);
        self::assertSame(1000, self::entry($scores, '1')->score);
    }

    public function testBestAttemptIsKeptWhateverItsOrder(): void
    {
        self::assertSame(320, EventScorer::bestResult([Attempt::valid(250), Attempt::foul(), Attempt::valid(320)], Better::Higher));
        self::assertSame(250, EventScorer::bestResult([Attempt::valid(320), Attempt::valid(250)], Better::Lower));
        self::assertNull(EventScorer::bestResult([Attempt::foul()], Better::Lower));
        self::assertNull(EventScorer::bestResult([], Better::Lower));
    }

    public function testSingleOrEqualValidResultsGetMaxScore(): void
    {
        $single = EventScorer::score(self::variant(), [
            new EventEntry('1', true, [Attempt::valid(500)]),
            new EventEntry('2', true, [Attempt::foul()]),
        ]);
        self::assertSame(['1' => 1000, '2' => 1], self::points($single->entries));

        $equal = EventScorer::score(self::variant(), [
            new EventEntry('1', true, [Attempt::valid(500)]),
            new EventEntry('2', true, [Attempt::valid(500)]),
        ]);
        self::assertSame(['1' => 1000, '2' => 1000], self::points($equal->entries));
        self::assertSame(1, self::entry($equal, '2')->rank);
    }

    public function testAbsentsFoulsAndPendingNeverSetTheBounds(): void
    {
        $scores = EventScorer::score(self::variant(Measure::Distance, Better::Higher), [
            new EventEntry('1', true, [Attempt::valid(300)]),
            new EventEntry('2', true, [Attempt::valid(200)]),
            new EventEntry('3', false, [Attempt::valid(900)]), // absent: ignored even with a result
            new EventEntry('4', true, [Attempt::foul()]),
            new EventEntry('5', true, []),
        ]);

        self::assertSame(300, $scores->best);
        self::assertSame(200, $scores->worst);
        self::assertSame(EntryStatus::Absent, self::entry($scores, '3')->status);
        self::assertNull(self::entry($scores, '3')->score);
        self::assertSame(EntryStatus::Pending, self::entry($scores, '5')->status);
        self::assertNull(self::entry($scores, '5')->score);
        self::assertNull(self::entry($scores, '5')->rank);
    }

    public function testNoValidResultAtAll(): void
    {
        $scores = EventScorer::score(self::variant(), [
            new EventEntry('1', true, [Attempt::foul()]),
            new EventEntry('2', true, []),
        ], closed: true);

        self::assertNull($scores->best);
        self::assertSame(['1' => 1, '2' => 0], self::points($scores->entries));
    }

    public function testPerEventScaleOverride(): void
    {
        $rules = new ScoringRules(maxScore: 1000, floor: 1, foulScore: 0);
        $scores = EventScorer::score(self::variant(rules: $rules), [
            new EventEntry('1', true, [Attempt::valid(100)]),
            new EventEntry('2', true, [Attempt::valid(200)]),
            new EventEntry('3', true, [Attempt::foul()]),
        ]);

        self::assertSame(['1' => 1000, '2' => 1, '3' => 0], self::points($scores->entries));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function roundingCases(): iterable
    {
        // best 0, worst 1000, floor 100: score = 100 + (1000 - v) × 0.9
        yield 'exact' => [500, 550];
        yield 'half rounds up' => [995, 105]; // 104.5
        yield 'below half rounds down' => [996, 104]; // 103.6
        yield 'above half rounds up' => [994, 105]; // 105.4 → 105
    }

    #[DataProvider('roundingCases')]
    public function testRoundsHalfUpWithIntegers(int $value, int $expected): void
    {
        self::assertSame($expected, EventScorer::points($value, 0, 1000, new ScoringRules()));
    }

    public function testRoundingIsSymmetricInBothDirections(): void
    {
        // Same relative position, opposite directions → same points.
        $rules = new ScoringRules();
        self::assertSame(775, EventScorer::points(710, 620, 980, $rules));
        self::assertSame(775, EventScorer::points(890, 980, 620, $rules));
    }

    private static function entry(EventScores $scores, string $bib): EntryScore
    {
        $entry = $scores->entry($bib);
        self::assertNotNull($entry, "No entry for bib {$bib}");

        return $entry;
    }

    /**
     * @param list<EntryScore> $entries
     *
     * @return array<string, int|null>
     */
    private static function points(array $entries): array
    {
        $points = [];
        foreach ($entries as $entry) {
            $points[$entry->bib] = $entry->score;
        }

        return $points;
    }

    private static function variant(
        Measure $measure = Measure::Time,
        Better $better = Better::Lower,
        int $attempts = 1,
        ?ScoringRules $rules = null,
    ): Variant {
        return new Variant('test', 'junior', $measure, $better, $attempts, FoulMode::Strict, $rules ?? new ScoringRules());
    }
}
