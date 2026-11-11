<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Results;

use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\TimeFormat;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Measurement\EntryParser;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Domain\Results\EntryChecks;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use PHPUnit\Framework\TestCase;

final class EntryRulesTest extends TestCase
{
    public function testStrictFoulUsesAnAttemptLenientDoesNot(): void
    {
        $attempts = [self::attempt(1, 345), self::attempt(2, null)];

        $strict = new AttemptProgress(self::variant(FoulMode::Strict), $attempts);
        self::assertSame(2, $strict->used());
        self::assertTrue($strict->isFinished());

        $lenient = new AttemptProgress(self::variant(FoulMode::Lenient), $attempts);
        self::assertSame(1, $lenient->used());
        self::assertSame(2, $lenient->nextNumber());
        self::assertSame(345, $lenient->best());
    }

    public function testBestDependsOnDirectionAndModifiers(): void
    {
        $variant = new Variant('hockey', 'junior', Measure::Time, Better::Lower, 2, FoulMode::Strict, self::rules());
        $progress = new AttemptProgress($variant, [self::attempt(1, 2000, -300), self::attempt(2, 1800)]);
        self::assertSame(1700, $progress->best());
    }

    public function testWarnings(): void
    {
        $now = (int) strtotime('2027-06-06 10:00:00 UTC');
        $progress = new AttemptProgress(self::variant(FoulMode::Strict), [self::attempt(1, 345, 0, '2027-06-06 09:59:00')]);

        self::assertSame([], EntryChecks::check($progress, 300, true, true, $now));
        self::assertSame([EntryChecks::NOT_PRESENT, EntryChecks::IMPLAUSIBLE], EntryChecks::check($progress, 900, false, true, $now));
        self::assertSame([EntryChecks::DUPLICATE], EntryChecks::check($progress, 345, true, true, $now));
        self::assertSame([], EntryChecks::check($progress, 345, true, true, $now + 600), 'Same value much later: not a duplicate.');
        self::assertSame([], EntryChecks::check($progress, 345, true, false, $now), 'A correction is never a duplicate.');
        self::assertSame([], EntryChecks::check($progress, null, true, true, $now), 'A foul has no value to check.');

        $finished = new AttemptProgress(self::variant(FoulMode::Strict), [self::attempt(1, 345), self::attempt(2, 350)]);
        self::assertSame([EntryChecks::ATTEMPTS_USED], EntryChecks::check($finished, 300, true, true, $now + 3600));
    }

    public function testDigitsRoundTrip(): void
    {
        foreach ([[1225, TimeFormat::Seconds], [8324, TimeFormat::Minutes], [5999, TimeFormat::Minutes], [6000, TimeFormat::Minutes]] as [$value, $format]) {
            $digits = EntryParser::toDigits($value, Measure::Time, $format);
            self::assertSame($value, EntryParser::parse($digits, Measure::Time, $format), $digits);
        }
        self::assertSame('12324', EntryParser::toDigits(8324, Measure::Time, TimeFormat::Minutes));
    }

    public function testHelpMarkdownIsSafe(): void
    {
        $html = (new HelpPages('/nonexistent', '/nonexistent'))->convert("# Title\n\n<script>alert(1)</script>\n\n[link](javascript:alert(1))");
        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    private static function variant(FoulMode $foulMode): Variant
    {
        return new Variant('long_jump', 'senior', Measure::Distance, Better::Higher, 2, $foulMode, self::rules(), plausibleRange: [50, 500]);
    }

    private static function rules(): ScoringRules
    {
        return new ScoringRules(1000, 100, 1, 0);
    }

    private static function attempt(int $id, ?int $measured, int $modifierTotal = 0, string $at = '2027-06-06 09:00:00'): StoredAttempt
    {
        return new StoredAttempt($id, $id, 1, 1, 'long_jump.senior', $measured, [], $modifierTotal, [], $at, 'arbitre', 'Paul');
    }
}
