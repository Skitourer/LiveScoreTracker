<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies versioned SQL migrations in filename order and records them.
 *
 * Migration files are named `NNNN_description.sql` and must use SQL that works
 * on both MySQL and SQLite. Statements end with a semicolon at the end of a line.
 * Two placeholders cover the differences between the two databases:
 *   {{id}}            auto-incremented primary key column definition
 *   {{table_options}} table options (engine and character set on MySQL)
 */
final class Migrator
{
    private const TABLE = 'schema_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {}

    /**
     * Applies every pending migration.
     *
     * @return list<string> names of the migrations applied by this call
     */
    public function migrate(): array
    {
        $this->ensureTable();
        $applied = [];

        foreach ($this->pending() as $name => $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException(\sprintf('Cannot read migration "%s".', $name));
            }

            try {
                foreach (self::splitStatements($this->adaptToDriver($sql)) as $statement) {
                    $this->pdo->exec($statement);
                }
                $insert = $this->pdo->prepare(
                    'INSERT INTO ' . self::TABLE . ' (name, applied_at) VALUES (:name, :applied_at)',
                );
                $insert->execute(['name' => $name, 'applied_at' => gmdate('Y-m-d H:i:s')]);
            } catch (Throwable $exception) {
                throw new RuntimeException(\sprintf('Migration "%s" failed: %s', $name, $exception->getMessage()), 0, $exception);
            }

            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Lists migrations found on disk but not yet applied.
     *
     * @return array<string, string> migration name => file path
     */
    public function pending(): array
    {
        $this->ensureTable();
        $done = array_flip($this->appliedNames());
        $pending = [];

        foreach ($this->available() as $name => $path) {
            if (!isset($done[$name])) {
                $pending[$name] = $path;
            }
        }

        return $pending;
    }

    /**
     * @return list<string>
     */
    public function appliedNames(): array
    {
        $this->ensureTable();
        $statement = $this->pdo->query('SELECT name FROM ' . self::TABLE . ' ORDER BY name');
        if ($statement === false) {
            return [];
        }

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (\is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*$/m', $withoutComments) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn(string $part): bool => $part !== '',
        ));
    }

    /**
     * Replaces the placeholders with the current database's syntax.
     */
    public function adaptToDriver(string $sql): string
    {
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

        return strtr($sql, [
            '{{id}}' => $isMysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{{table_options}}' => $isMysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        ]);
    }

    /**
     * @return array<string, string> migration name => file path, sorted by name
     */
    private function available(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
        $migrations = [];
        foreach ($files as $file) {
            $migrations[basename($file, '.sql')] = $file;
        }
        ksort($migrations, SORT_STRING);

        return $migrations;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'name VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'applied_at VARCHAR(19) NOT NULL)',
        );
    }
}
