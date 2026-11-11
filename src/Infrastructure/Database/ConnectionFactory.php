<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Database;

use LiveScoreTracker\Infrastructure\Settings;
use PDO;
use RuntimeException;

/**
 * Creates the PDO connection for MySQL (production) or SQLite (development, tests).
 */
final class ConnectionFactory
{
    public static function create(Settings $settings): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($settings->dbDriver === Settings::DRIVER_MYSQL) {
            $dsn = \sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $settings->dbHost,
                $settings->dbPort,
                $settings->dbName,
            );

            // Report matched rows (not only changed ones) so "UPDATE … then INSERT if none" works.
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone = '+00:00'";

            return new PDO($dsn, $settings->dbUser, $settings->dbPassword, $options);
        }

        $path = $settings->sqlitePath;
        if ($path !== ':memory:') {
            $directory = \dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
                throw new RuntimeException(\sprintf('Cannot create the data folder "%s".', $directory));
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
