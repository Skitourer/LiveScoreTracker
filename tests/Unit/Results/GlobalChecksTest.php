<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Results;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Results\GlobalChecks;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use PHPUnit\Framework\TestCase;

/**
 * Checks against the other results (spec § 10.1): far from the median, wrong distance probable.
 */
final class GlobalChecksTest extends TestCase
{
    private int $nextId = 1;

    public function testFlagsAResultFarFromTheMedianOnceEnoughResultsAreKnown(): void
    {
        $jump = self::jump();
        // Five girls around 3 m, one at 9,50 m (typo for 3,50 m?), one at 1,20 m.
        $children = $this->children($jump, 'CM1-girl', [300, 310, 290, 320, 305, 950, 120]);
        $flags = GlobalChecks::check([$jump], $children);

        self::assertSame([GlobalChecks::FAR_FROM_MEDIAN], $flags[6] ?? null);
        self::assertSame([GlobalChecks::FAR_FROM_MEDIAN], $flags[7] ?? null);
        self::assertCount(2, $flags, 'Ordinary results are not flagged.');

        // Too few other results: nothing is said.
        self::assertSame([], GlobalChecks::check([$jump], $this->children($jump, 'CM1-girl', [300, 310, 290, 950])));
    }

    public function testOnlyTheRetainedResultIsCompared(): void
    {
        $jump = self::jump();
        $children = $this->children($jump, 'CM1-girl', [300, 310, 290, 320, 305]);
        // A sixth child: a poor first jump (not retained) and a normal one.
        $children[] = ['attempts' => [$this->attempt($jump, 100), $this->attempt($jump, 300)], 'category' => 'CM1-girl'];

        self::assertSame([], GlobalChecks::check([$jump], $children));
    }

    public function testFlagsATimeTypicalOfTheOtherDistance(): void
    {
        $short = self::sprint('junior', '30 m');
        $long = self::sprint('senior', '40 m');
        $children = [
            ...$this->children($short, 'CE1-boy', [600, 610, 590, 620, 605, 850, 700]),
            ...$this->children($long, 'CM1-boy', [700, 710, 690, 720, 705, 520, 600]),
        ];
        $flags = GlobalChecks::check([$short, $long], $children);

        // Medians of the other children: 6,08 s (30 m) and 7,03 s (40 m). 8,50 s on the 30 m is a
        // typical 40 m time (≥ 6,08 × 40/30), 5,20 s on the 40 m a typical 30 m time (≤ 7,03 × 30/40).
        // Slow (7,00 s) or fast (6,00 s) children are not flagged.
        self::assertSame([GlobalChecks::WRONG_DISTANCE], $flags[6] ?? null);
        self::assertSame([GlobalChecks::WRONG_DISTANCE], $flags[13] ?? null);
        self::assertCount(2, $flags);

        // Without distances in the labels (e.g. hockey), nothing is said.
        $hockey = new Variant('hockey', 'junior', Measure::Time, Better::Lower, 2, FoulMode::Strict, new ScoringRules());
        $hockeySenior = new Variant('hockey', 'senior', Measure::Time, Better::Lower, 2, FoulMode::Strict, new ScoringRules());
        self::assertSame([], GlobalChecks::check([$hockey, $hockeySenior], [
            ...$this->children($hockey, 'CE1-boy', [3000, 3100, 2900, 3050, 2950, 4500]),
            ...$this->children($hockeySenior, 'CM1-boy', [4500, 4400, 4600, 4550, 4450, 3000]),
        ]));
    }

    public function testMedian(): void
    {
        self::assertSame(3.0, GlobalChecks::median([5, 1, 3]));
        self::assertSame(2.5, GlobalChecks::median([4, 1, 3, 2]));
        self::assertSame(0.0, GlobalChecks::median([]));
    }

    /**
     * @param list<int> $values
     *
     * @return list<array{attempts: list<StoredAttempt>, category: string}>
     */
    private function children(Variant $variant, string $category, array $values): array
    {
        return array_map(fn(int $value): array => ['attempts' => [$this->attempt($variant, $value)], 'category' => $category], $values);
    }

    private function attempt(Variant $variant, int $value): StoredAttempt
    {
        $id = $this->nextId++;

        return new StoredAttempt($id, $id, $id, $id, $variant->id(), $value, [], 0, [], '2027-03-20 10:00:00', 'arbitre', 'Paul');
    }

    private static function jump(): Variant
    {
        return new Variant('long_jump', 'senior', Measure::Distance, Better::Higher, 2, FoulMode::Strict, new ScoringRules());
    }

    private static function sprint(string $group, string $label): Variant
    {
        return new Variant('sprint', $group, Measure::Time, Better::Lower, 1, FoulMode::Strict, new ScoringRules(), label: $label);
    }
}
