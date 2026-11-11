<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure;

use InvalidArgumentException;

/**
 * Immutable application settings, read from environment variables.
 *
 * Real values live in the server's `.env` file, never in the repository.
 */
final class Settings
{
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_MYSQL = 'mysql';

    /**
     * @param string $rootDir     absolute path to the project root
     * @param string $dataDir     absolute path to the private data folder (outside the web root)
     * @param string $environment "production", "test" or "development"
     */
    public function __construct(
        public readonly string $rootDir,
        public readonly string $dataDir,
        public readonly string $environment,
        public readonly bool $debug,
        public readonly string $dbDriver,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $sqlitePath,
        public readonly string $deployToken,
        public readonly string $appUrl = '',
        public readonly int $sessionIdleMinutes = 240,
        public readonly string $mailTransport = 'log',
        public readonly string $mailHost = '',
        public readonly int $mailPort = 465,
        public readonly string $mailEncryption = 'ssl',
        public readonly string $mailUser = '',
        public readonly string $mailPassword = '',
        public readonly string $mailFrom = '',
        public readonly string $mailFromName = '',
        public readonly string $timezone = 'Europe/Paris',
    ) {
        if (!\in_array($dbDriver, [self::DRIVER_SQLITE, self::DRIVER_MYSQL], true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported DB_DRIVER "%s".', $dbDriver));
        }
    }

    /**
     * Builds settings from an environment array (usually $_ENV after loading `.env`).
     *
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(string $rootDir, array $env): self
    {
        $read = static function (string $key, string $default = '') use ($env): string {
            $value = $env[$key] ?? $default;

            return \is_scalar($value) ? trim((string) $value) : $default;
        };

        $dataDir = self::absolutePath($rootDir, $read('DATA_DIR', 'data'));

        return new self(
            rootDir: $rootDir,
            dataDir: $dataDir,
            environment: $read('APP_ENV', 'production'),
            debug: filter_var($read('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
            dbDriver: strtolower($read('DB_DRIVER', self::DRIVER_SQLITE)),
            dbHost: $read('DB_HOST', 'localhost'),
            dbPort: (int) $read('DB_PORT', '3306'),
            dbName: $read('DB_NAME'),
            dbUser: $read('DB_USER'),
            dbPassword: $read('DB_PASSWORD'),
            sqlitePath: self::absolutePath($dataDir, $read('DB_SQLITE_PATH', 'app.sqlite')),
            deployToken: $read('DEPLOY_TOKEN'),
            appUrl: rtrim($read('APP_URL'), '/'),
            sessionIdleMinutes: max(5, (int) $read('SESSION_IDLE_MINUTES', '240')),
            mailTransport: strtolower($read('MAIL_TRANSPORT', 'log')),
            mailHost: $read('MAIL_HOST'),
            mailPort: (int) $read('MAIL_PORT', '465'),
            mailEncryption: strtolower($read('MAIL_ENCRYPTION', 'ssl')),
            mailUser: $read('MAIL_USER'),
            mailPassword: $read('MAIL_PASSWORD'),
            mailFrom: $read('MAIL_FROM'),
            mailFromName: $read('MAIL_FROM_NAME', 'LiveScoreTracker'),
            timezone: \in_array($read('APP_TIMEZONE', 'Europe/Paris'), timezone_identifiers_list(), true) ? $read('APP_TIMEZONE', 'Europe/Paris') : 'UTC',
        );
    }

    public function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    private static function absolutePath(string $base, string $path): string
    {
        if ($path === ':memory:' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return rtrim($base, '/\\') . '/' . $path;
    }
}
