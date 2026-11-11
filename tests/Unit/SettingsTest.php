<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit;

use InvalidArgumentException;
use LiveScoreTracker\Infrastructure\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testDefaultsAreSafe(): void
    {
        $settings = Settings::fromEnvironment('/srv/app', []);

        self::assertSame('production', $settings->environment);
        self::assertFalse($settings->debug);
        self::assertSame(Settings::DRIVER_SQLITE, $settings->dbDriver);
        self::assertSame('/srv/app/data', $settings->dataDir);
        self::assertSame('/srv/app/data/app.sqlite', $settings->sqlitePath);
        self::assertSame('', $settings->deployToken);
        self::assertTrue($settings->isProduction());
    }

    public function testReadsEnvironmentValues(): void
    {
        $settings = Settings::fromEnvironment('/srv/app', [
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'DATA_DIR' => '/var/private',
            'DB_DRIVER' => 'MySQL',
            'DB_HOST' => 'db.example.test',
            'DB_PORT' => '3307',
            'DB_NAME' => 'demo',
            'DB_USER' => 'demo_user',
            'DB_PASSWORD' => 'secret',
            'DEPLOY_TOKEN' => ' token ',
        ]);

        self::assertSame('test', $settings->environment);
        self::assertTrue($settings->debug);
        self::assertSame('/var/private', $settings->dataDir);
        self::assertSame(Settings::DRIVER_MYSQL, $settings->dbDriver);
        self::assertSame('db.example.test', $settings->dbHost);
        self::assertSame(3307, $settings->dbPort);
        self::assertSame('token', $settings->deployToken);
        self::assertFalse($settings->isProduction());
    }

    public function testKeepsInMemorySqlite(): void
    {
        $settings = Settings::fromEnvironment('/srv/app', ['DB_SQLITE_PATH' => ':memory:']);

        self::assertSame(':memory:', $settings->sqlitePath);
    }

    public function testRejectsUnknownDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Settings::fromEnvironment('/srv/app', ['DB_DRIVER' => 'oracle']);
    }
}
