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
