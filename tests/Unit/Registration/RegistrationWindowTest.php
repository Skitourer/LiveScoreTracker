<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Registration;

use DateTimeImmutable;
use DateTimeZone;
use LiveScoreTracker\Application\RegistrationWindow;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Registration;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Database\ConnectionFactory;
use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Where registration stands (spec § 5.2), for the public home page.
 */
final class RegistrationWindowTest extends TestCase
{
    private string $dataDir;
    private Settings $settings;
    private RegistrationRepository $registrations;
    private AppState $appState;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-window-' . bin2hex(random_bytes(4));
        $this->settings = Settings::fromEnvironment(\dirname(__DIR__, 3), ['APP_ENV' => 'test', 'DATA_DIR' => $this->dataDir, 'DB_DRIVER' => 'sqlite']);
        $pdo = ConnectionFactory::create($this->settings);
        (new Migrator($pdo, \dirname(__DIR__, 3) . '/migrations'))->migrate();
        $this->appState = new AppState($pdo);
        $this->registrations = new RegistrationRepository($pdo, new Clock(), $this->appState);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testStatesAlongTheWindow(): void
    {
        $config = $this->config(quota: 3);
        // Dates are Paris wall-clock times: opens 15 March 2027 08:00 = 07:00 UTC.
        self::assertSame(RegistrationWindow::UPCOMING, $this->at('2027-03-15 06:59:00', $config)['state']);
        $open = $this->at('2027-03-15 07:00:00', $config);
        self::assertSame(RegistrationWindow::OPEN, $open['state']);
        self::assertSame(3, $open['remaining']);
        self::assertSame(RegistrationWindow::CLOSED, $this->at('2027-05-30 22:00:00', $config)['state']);
        self::assertSame(RegistrationWindow::FULL, $this->at('2027-04-01 12:00:00', $this->config(quota: 0))['state']);
        self::assertSame(RegistrationWindow::UNSCHEDULED, $this->at('2027-04-01 12:00:00', $this->config(quota: 3, scheduled: false))['state']);
    }

    public function testAnAdminMayForceTheStateWhateverTheDates(): void
    {
        $config = $this->config(quota: 3);
        $this->window('2027-01-01 12:00:00')->force(RegistrationWindow::OPEN);
        self::assertSame(RegistrationWindow::OPEN, $this->at('2027-01-01 12:00:00', $config)['state']);
        self::assertSame(RegistrationWindow::FULL, $this->at('2027-01-01 12:00:00', $this->config(quota: 0))['state'], 'The quota still applies');
        self::assertTrue($this->window('2027-01-01 12:00:00')->acceptsRegistrations($config));

        $this->window('2027-04-01 12:00:00')->force(RegistrationWindow::CLOSED);
        self::assertSame(RegistrationWindow::CLOSED, $this->at('2027-04-01 12:00:00', $config)['state']);
        self::assertFalse($this->window('2027-04-01 12:00:00')->acceptsRegistrations($config));

        $this->window('2027-04-01 12:00:00')->force('');
        self::assertSame(RegistrationWindow::OPEN, $this->at('2027-04-01 12:00:00', $config)['state']);
        self::assertSame('', $this->at('2027-04-01 12:00:00', $config)['forced']);
    }

    /**
     * @return array{state: string, opens_at: DateTimeImmutable|null, closes_at: DateTimeImmutable|null, quota: int, remaining: int, forced: string}
     */
    private function at(string $utc, CompetitionConfig $config): array
    {
        return $this->window($utc)->status($config);
    }

    private function window(string $utc): RegistrationWindow
    {
        $clock = new class ($utc) extends Clock {
            public function __construct(private readonly string $utc) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->utc, new DateTimeZone('UTC'));
            }
        };

        return new RegistrationWindow($this->registrations, $clock, $this->settings, $this->appState);
    }

    private function config(int $quota, bool $scheduled = true): CompetitionConfig
    {
        $example = (new ConfigRepository($this->dataDir, \dirname(__DIR__, 3) . '/config.example'))->get();
        $registration = new Registration(
            $scheduled ? new DateTimeImmutable('2027-03-15 08:00:00') : null,
            $scheduled ? new DateTimeImmutable('2027-05-30 23:59:00') : null,
            $quota,
            1,
        );

        return new CompetitionConfig(
            $example->edition,
            $example->levels,
            $example->genders,
            $example->schools,
            $example->levelGroups,
            $registration,
            $example->scoring,
            $example->workshops,
            $example->importColumns,
            $example->contacts,
        );
    }
}
