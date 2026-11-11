<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit;

use LiveScoreTracker\Infrastructure\Database\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lst-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testAppliesMigrationsOnceInOrder(): void
    {
        file_put_contents($this->dir . '/0002_add_row.sql', "INSERT INTO demo (id) VALUES (1);\n");
        file_put_contents($this->dir . '/0001_create.sql', "-- comment\nCREATE TABLE demo (id INTEGER);\n");
        $pdo = $this->pdo();
        $migrator = new Migrator($pdo, $this->dir);

        self::assertSame(['0001_create', '0002_add_row'], array_keys($migrator->pending()));
        self::assertSame(['0001_create', '0002_add_row'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame(['0001_create', '0002_add_row'], $migrator->appliedNames());
        $count = $pdo->query('SELECT COUNT(*) FROM demo');
        self::assertNotFalse($count);
        self::assertSame(1, $count->fetchColumn());
    }

    public function testReportsFailingMigration(): void
    {
        file_put_contents($this->dir . '/0001_broken.sql', 'CREATE TABLE;');
        $migrator = new Migrator($this->pdo(), $this->dir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration "0001_broken" failed');

        $migrator->migrate();
    }

    public function testProjectMigrationsRunOnSqlite(): void
    {
        $migrator = new Migrator($this->pdo(), \dirname(__DIR__, 2) . '/migrations');

        self::assertNotSame([], $migrator->migrate());
        self::assertSame([], $migrator->pending());
    }

    public function testSplitsStatements(): void
    {
        $sql = "-- header\nCREATE TABLE a (x TEXT);\n\nCREATE TABLE b (y TEXT);\n";

        self::assertSame(['CREATE TABLE a (x TEXT)', 'CREATE TABLE b (y TEXT)'], Migrator::splitStatements($sql));
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
