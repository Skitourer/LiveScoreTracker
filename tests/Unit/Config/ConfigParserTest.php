<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Config;

use Internal\Toml\Toml;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\ConfigParser;
use LiveScoreTracker\Domain\Config\CounterLimitType;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\TieBreaker;
use LiveScoreTracker\Domain\Config\TimeFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigParserTest extends TestCase
{
    private const MINIMAL = <<<'TOML'
        [edition]
        name = "Games"
        organizer = "Club"
        date = 2027-06-06

        [participants]
        levels = ["CP", "CE1"]
        genders = ["girl", "boy"]

        [level_groups]
        junior = ["CP", "CE1"]

        [[workshops]]
        id = "sprint"

          [[workshops.variants]]
          group = "junior"
          measure = "time"
          better = "lower"

        TOML;

    public function testExampleConfigurationIsValid(): void
    {
        $config = self::parseFile(\dirname(__DIR__, 3) . '/config.example/competition.toml');

        self::assertCount(10, $config->categories());
        self::assertSame('CE2-girl', $config->categories()[4]->id());
        self::assertSame('senior', $config->groupOf('CM1'));
        self::assertCount(6, $config->variantsForGroup('junior'));
        self::assertCount(6, $config->variantsForGroup('senior'));
        self::assertSame([TieBreaker::FirstPlaces, TieBreaker::Top3Places, TieBreaker::Youngest], $config->scoring->tieBreakers);
        self::assertSame(100, $config->scoring->floor);

        $sprint = $config->variant('sprint.junior');
        self::assertSame(Measure::Time, $sprint->measure);
        self::assertSame(TimeFormat::Seconds, $sprint->timeFormat);
        self::assertSame([400, 1200], $sprint->plausibleRange);
        self::assertSame(2, $sprint->bibsPerTimer);

        $hockey = $config->variant('hockey.senior');
        self::assertSame(-300, $hockey->modifiers[0]->value);
        self::assertSame(2, $hockey->attempts);

        self::assertSame(FoulMode::Lenient, $config->variant('long_jump.senior')->foulMode);
        self::assertSame([50, 500], $config->variant('long_jump.senior')->plausibleRange);

        $basket = $config->variant('basketball.senior');
        self::assertSame(CounterLimitType::Duration, $basket->limit?->type);
        self::assertSame(90, $basket->limit->value);
        self::assertSame(['basket' => 3, 'rim' => 2, 'board' => 1], array_column(array_map(static fn($a) => (array) $a, $basket->actions), 'points', 'id'));

        self::assertSame('Child last name', $config->importColumns['last_name']);
        self::assertSame('data_entry', $config->contacts[0]->role);
        self::assertSame('2027-06-06', $config->edition->date->format('Y-m-d'));
        self::assertSame('09:00', $config->edition->start);
    }

    public function testMinimalConfigurationUsesDefaults(): void
    {
        $config = self::parse(self::MINIMAL);

        self::assertSame('fr', $config->edition->defaultLocale);
        self::assertSame(100, $config->registration->quota);
        self::assertSame(1, $config->registration->firstBib);
        self::assertSame(1000, $config->scoring->maxScore);
        self::assertSame(1, $config->variant('sprint.junior')->attempts);
        self::assertSame(FoulMode::Strict, $config->variant('sprint.junior')->foulMode);
    }

    public function testPerEventScaleOverride(): void
    {
        $config = self::parse(self::MINIMAL . "  floor = 1\n  foul_score = 0\n");

        self::assertSame(1, $config->variant('sprint.junior')->scoring->floor);
        self::assertSame(100, $config->scoring->floor);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCases(): iterable
    {
        yield 'unknown key (typo)' => [
            str_replace('measure = "time"', 'mesure = "time"', self::MINIMAL),
            'workshops[0].variants[0].mesure: unknown setting',
        ];
        yield 'unknown measure' => [
            str_replace('measure = "time"', 'measure = "speed"', self::MINIMAL),
            'workshops[0].variants[0].measure: must be one of time, distance, score, counter',
        ];
        yield 'unknown group' => [
            str_replace('group = "junior"', 'group = "senior"', self::MINIMAL),
            'workshops[0].variants[0].group: unknown level group "senior"',
        ];
        yield 'level in no group' => [
            str_replace('junior = ["CP", "CE1"]', 'junior = ["CP"]', self::MINIMAL),
            'level_groups: level "CE1" belongs to no group',
        ];
        yield 'level in two groups' => [
            str_replace('junior = ["CP", "CE1"]', "junior = [\"CP\", \"CE1\"]\nsenior = [\"CE1\"]", self::MINIMAL),
            'level_groups.senior: level "CE1" is already in group "junior"',
        ];
        yield 'foul scores like the worst result' => [
            str_replace('[participants]', "[scoring]\nfloor = 1\n\n[participants]", self::MINIMAL),
            'scoring.foul_score: must be lower than floor',
        ];
        yield 'floor above max' => [
            str_replace('[participants]', "[scoring]\nfloor = 2000\n\n[participants]", self::MINIMAL),
            'scoring.floor: must be lower than max_score',
        ];
        yield 'per-event scale override checked' => [
            self::MINIMAL . "  floor = 1\n",
            'workshops[0].variants[0].foul_score: must be lower than floor',
        ];
        yield 'unknown tie breaker' => [
            str_replace('[participants]', "[scoring]\ntie_breakers = [\"oldest\"]\n\n[participants]", self::MINIMAL),
            'scoring.tie_breakers[0]: must be one of first_places, top3_places, youngest',
        ];
        yield 'counter with two attempts' => [
            str_replace(['measure = "time"', 'better = "lower"'], ['measure = "counter"', "better = \"higher\"\n  attempts = 2\n  limit = { type = \"shots\", value = 10 }\n  actions = [ { id = \"basket\", points = 3 } ]"], self::MINIMAL),
            'workshops[0].variants[0].attempts: counter events allow a single attempt',
        ];
        yield 'counter without actions' => [
            str_replace(['measure = "time"', 'better = "lower"'], ['measure = "counter"', "better = \"higher\"\n  limit = { type = \"shots\", value = 10 }"], self::MINIMAL),
            'workshops[0].variants[0].actions: a counter event needs at least one action',
        ];
        yield 'bad duration' => [
            str_replace(['measure = "time"', 'better = "lower"'], ['measure = "counter"', "better = \"higher\"\n  limit = { type = \"duration\", value = \"1:75\" }\n  actions = [ { id = \"basket\", points = 3 } ]"], self::MINIMAL),
            'workshops[0].variants[0].limit.value: must be a duration',
        ];
        yield 'time format on a distance' => [
            str_replace('measure = "time"', "measure = \"distance\"\n  time_format = \"ss.cc\"", self::MINIMAL),
            'workshops[0].variants[0].time_format: only applies to time events',
        ];
        yield 'reversed range' => [
            self::MINIMAL . "  plausible_range = [12.0, 4.0]\n",
            'workshops[0].variants[0].plausible_range: minimum must be lower than maximum',
        ];
        yield 'duplicate workshop' => [
            self::MINIMAL . "\n[[workshops]]\nid = \"sprint\"\n  [[workshops.variants]]\n  group = \"junior\"\n  measure = \"time\"\n  better = \"lower\"\n",
            'workshops[1].id: "sprint" is used twice',
        ];
        yield 'missing edition date' => [
            str_replace('date = 2027-06-06', '', self::MINIMAL),
            'edition.date: is required',
        ];
        yield 'group without event' => [
            str_replace('junior = ["CP", "CE1"]', "junior = [\"CP\"]\nsenior = [\"CE1\"]", self::MINIMAL),
            'level_groups.senior: no event is defined for this level group',
        ];
    }

    #[DataProvider('invalidCases')]
    public function testReportsInvalidConfiguration(string $toml, string $expectedError): void
    {
        try {
            self::parse($toml);
            self::fail('InvalidConfig was not thrown.');
        } catch (InvalidConfig $exception) {
            $matches = array_filter($exception->errors, static fn(string $e): bool => str_starts_with($e, $expectedError));
            self::assertNotEmpty($matches, "Expected error not found. Got:\n" . implode("\n", $exception->errors));
        }
    }

    public function testReportsAllErrorsAtOnce(): void
    {
        $toml = str_replace(['measure = "time"', 'better = "lower"'], ['measure = "speed"', 'better = "faster"'], self::MINIMAL);

        try {
            self::parse($toml);
            self::fail('InvalidConfig was not thrown.');
        } catch (InvalidConfig $exception) {
            self::assertGreaterThanOrEqual(2, \count($exception->errors));
        }
    }

    public function testGlobalScaleErrorIsReportedOnce(): void
    {
        try {
            self::parse(str_replace('[participants]', "[scoring]\nfloor = 1\n\n[participants]", self::MINIMAL));
            self::fail('InvalidConfig was not thrown.');
        } catch (InvalidConfig $exception) {
            self::assertCount(1, $exception->errors);
        }
    }

    private static function parse(string $toml): CompetitionConfig
    {
        return ConfigParser::parse(Toml::parseToArray($toml));
    }

    private static function parseFile(string $path): CompetitionConfig
    {
        return self::parse((string) file_get_contents($path));
    }
}
