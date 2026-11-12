<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Results;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\CounterAction;
use LiveScoreTracker\Domain\Config\CounterLimit;
use LiveScoreTracker\Domain\Config\CounterLimitType;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Results\CounterTally;
use LiveScoreTracker\Domain\Results\EntryChecks;
use PHPUnit\Framework\TestCase;

final class CounterTallyTest extends TestCase
{
    public function testTotalUsesThePointsOfEachAction(): void
    {
        $tally = new CounterTally(['basket' => 3, 'rim' => 2, 'board' => 1]);
        self::assertSame(14, $tally->total(self::variant(CounterLimitType::Duration, 90)));
        self::assertSame(6, $tally->shots());
        self::assertSame(0, (new CounterTally([]))->total(self::variant(CounterLimitType::Duration, 90)));
    }

    public function testShotLimitWarnsWhenExceeded(): void
    {
        $variant = self::variant(CounterLimitType::Shots, 10);
        self::assertSame([], (new CounterTally(['basket' => 4], 6))->warnings($variant));
        self::assertSame([EntryChecks::TOO_MANY_SHOTS], (new CounterTally(['basket' => 4], 7))->warnings($variant));
        self::assertSame([], (new CounterTally(['basket' => 40]))->warnings(self::variant(CounterLimitType::Duration, 90)), 'No shot count with a time limit.');
    }

    public function testDetailsRoundTrip(): void
    {
        $tally = new CounterTally(['basket' => 2], 3);
        $details = $tally->toDetails();
        self::assertSame(['source' => 'counter', 'actions' => ['basket' => 2], 'missed' => 3], $details);
        $back = CounterTally::fromDetails($details);
        self::assertNotNull($back);
        self::assertSame($tally->counts, $back->counts);
        self::assertSame(3, $back->missed);
        self::assertNull(CounterTally::fromDetails(['source' => 'timer']));
    }

    private static function variant(CounterLimitType $type, int $value): Variant
    {
        return new Variant(
            'basketball',
            'senior',
            Measure::Counter,
            Better::Higher,
            1,
            FoulMode::Strict,
            new ScoringRules(1000, 100, 1, 0),
            limit: new CounterLimit($type, $value),
            actions: [new CounterAction('basket', 3), new CounterAction('rim', 2), new CounterAction('board', 1)],
        );
    }
}
