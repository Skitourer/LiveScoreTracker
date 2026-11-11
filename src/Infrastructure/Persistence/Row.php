<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use PDO;
use PDOStatement;

/**
 * Typed access to database rows (drivers return strings, ints or null).
 */
final class Row
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (\is_array($row)) {
                $typed = [];
                foreach ($row as $key => $value) {
                    $typed[(string) $key] = $value;
                }
                $rows[] = $typed;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        return self::nullableString($row, $key) ?? '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return \is_numeric($value) ? (int) $value : 0;
    }
}
