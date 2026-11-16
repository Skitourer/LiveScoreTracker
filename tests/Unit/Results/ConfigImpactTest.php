<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Results;

use Internal\Toml\Toml;
use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\ConfigParser;
use LiveScoreTracker\Domain\Config\FoulMode;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\ScoringRules;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Domain\Results\ConfigImpact;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use PHPUnit\Framework\TestCase;

/**
 * Step 12b: changes while the competition is running (spec § 10.4).
 */
final class ConfigImpactTest extends TestCase
{
    public function testOnlyTheFirstAllowedAttemptsCount(): void
    {
        $attempts = [self::attempt(1, 300), self::attempt(2, null), self::attempt(3, 400)];

        // Strict, 2 attempts: the foul uses the second one, the third is excluded.
        $strict = new AttemptProgress(self::variant(2, FoulMode::Strict), $attempts);
        self::assertCount(2, $strict->retained());
        self::assertCount(1, $strict->excluded());
        self::assertSame(300, $strict->best());
        self::assertTrue($strict->isFinished());

        // Lenient: the foul does not count, all three are kept.
        $lenient = new AttemptProgress(self::variant(2, FoulMode::Lenient), $attempts);
        self::assertCount(3, $lenient->retained());
        self::assertSame(400, $lenient->best());

        // Fewer attempts: only the first one counts.
        self::assertSame(300, (new AttemptProgress(self::variant(1, FoulMode::Lenient), $attempts))->best());
    }

    public function testMoreAttemptsGiveAnAttemptToDo(): void
    {
        $old = self::config();
        $new = self::config(['attempts = 2' => 'attempts = 3']);
        $impact = ConfigImpact::compare($old, $new, [self::child(1, 'CM1')], [1 => [self::attempt(1, 300), self::attempt(2, 310)]]);

        self::assertSame([['workshop' => 'long_jump', 'kind' => ConfigImpact::TO_DO]], self::kinds($impact));
        self::assertSame(1, ConfigImpact::childCount($impact));
    }

    public function testFewerAttemptsAndStrictModeExcludeAttempts(): void
    {
        $attempts = [1 => [self::attempt(1, null), self::attempt(2, 300), self::attempt(3, 310)]];
        $old = self::config();

        $fewer = ConfigImpact::compare($old, self::config(['attempts = 2' => 'attempts = 1']), [self::child(1, 'CM1')], $attempts);
        self::assertSame([['workshop' => 'long_jump', 'kind' => ConfigImpact::EXCLUDED]], self::kinds($fewer));

        $strict = ConfigImpact::compare($old, self::config(['foul_mode = "lenient"' => 'foul_mode = "strict"']), [self::child(1, 'CM1')], $attempts);
        self::assertSame([['workshop' => 'long_jump', 'kind' => ConfigImpact::EXCLUDED]], self::kinds($strict));
    }

    public function testDeactivatingAWorkshopExcludesItsResults(): void
    {
        $new = self::config(['id = "long_jump"' => "id = \"long_jump\"\ndisabled = true"]);
        self::assertTrue($new->isDisabled('long_jump.senior'));
        self::assertNotContains('long_jump.senior', array_map(static fn(Variant $v): string => $v->id(), $new->variants()));

        $impact = ConfigImpact::compare(self::config(), $new, [self::child(1, 'CM1'), self::child(2, 'CM2')], [1 => [self::attempt(1, 300)]]);
        self::assertSame([['workshop' => 'long_jump', 'kind' => ConfigImpact::EXCLUDED]], self::kinds($impact));
        self::assertSame(1, ConfigImpact::childCount($impact));
    }

    public function testAChildChangingLevelGroupGetsNewEvents(): void
    {
        $config = self::config();
        $child = self::child(1, 'CE1');
        $impact = ConfigImpact::compare($config, $config, [['registration' => $child['registration'], 'before' => 'CE1', 'after' => 'CM1']], []);

        // Long jump only exists for the senior group.
        self::assertContains(['workshop' => 'long_jump', 'kind' => ConfigImpact::TO_DO], self::kinds($impact));
    }

    /**
     * @param array<string, string> $replace
     */
    private static function config(array $replace = []): CompetitionConfig
    {
        $toml = strtr((string) file_get_contents(\dirname(__DIR__, 3) . '/config.example/competition.toml'), $replace);

        return ConfigParser::parse(Toml::parseToArray($toml));
    }

    private static function variant(int $attempts, FoulMode $mode): Variant
    {
        return new Variant('long_jump', 'senior', Measure::Distance, Better::Higher, $attempts, $mode, new ScoringRules(100, 20, 10, 0));
    }

    private static function attempt(int $id, ?int $measured): StoredAttempt
    {
        return new StoredAttempt($id, $id, 1, 1, 'long_jump.senior', $measured, [], 0, [], '2027-06-06 10:0' . $id . ':00', 'arbitre', 'Paul');
    }

    /**
     * @return array{registration: Registration, before: string, after: string}
     */
    private static function child(int $id, string $level): array
    {
        $data = new ChildData('FICTIF', 'Lina', null, 'girl', 'École des Tilleuls', $level, 'Parent', '0600000000', 'parent@example.org', false);
        $registration = new Registration($id, $id, RegistrationStatus::Registered, RegistrationSource::OnSite, $data, '2027-06-06 08:00:00', '2027-06-06 08:00:00', null);

        return ['registration' => $registration, 'before' => $level, 'after' => $level];
    }

    /**
     * @param list<array{workshop: string, kind: string, children: list<Registration>}> $impact
     *
     * @return list<array{workshop: string, kind: string}>
     */
    private static function kinds(array $impact): array
    {
        return array_map(static fn(array $line): array => ['workshop' => $line['workshop'], 'kind' => $line['kind']], $impact);
    }
}
