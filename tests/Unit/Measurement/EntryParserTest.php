<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Measurement;

use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\TimeFormat;
use LiveScoreTracker\Domain\Measurement\EntryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Measure, TimeFormat|null, int|null}>
     */
    public static function cases(): iterable
    {
        yield 'seconds' => ['1225', Measure::Time, TimeFormat::Seconds, 1225];
        yield 'minutes' => ['12324', Measure::Time, TimeFormat::Minutes, 8324];
        yield 'minutes, under one minute' => ['4512', Measure::Time, TimeFormat::Minutes, 4512];
        yield 'minutes, invalid seconds' => ['17500', Measure::Time, TimeFormat::Minutes, null];
        yield 'distance' => ['1234', Measure::Distance, null, 1234];
        yield 'score' => ['22', Measure::Score, null, 22];
        yield 'spaces are trimmed' => [' 22 ', Measure::Score, null, 22];
        yield 'decimal point refused' => ['12.25', Measure::Time, TimeFormat::Seconds, null];
        yield 'empty' => ['', Measure::Distance, null, null];
        yield 'negative' => ['-5', Measure::Score, null, null];
        yield 'too long' => ['12345678', Measure::Distance, null, null];
    }

    #[DataProvider('cases')]
    public function testParse(string $input, Measure $measure, ?TimeFormat $format, ?int $expected): void
    {
        self::assertSame($expected, EntryParser::parse($input, $measure, $format));
    }
}
