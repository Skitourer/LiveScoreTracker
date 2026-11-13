<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use DateTimeInterface;

/**
 * Differences between two decoded configurations, setting by setting
 * (import preview and audit log, spec § 12.3).
 */
final class ConfigDiff
{
    /**
     * @param array<mixed> $before
     * @param array<mixed> $after
     *
     * @return list<array{path: string, before: ?string, after: ?string}>
     */
    public static function compare(array $before, array $after): array
    {
        $old = self::flatten($before);
        $new = self::flatten($after);
        $changes = [];
        foreach ($old + $new as $path => $_) {
            $a = $old[$path] ?? null;
            $b = $new[$path] ?? null;
            if ($a !== $b) {
                $changes[] = ['path' => (string) $path, 'before' => $a, 'after' => $b];
            }
        }

        return $changes;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string> dotted path => value as text
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = \is_int($key) ? "{$prefix}[{$key}]" : ($prefix === '' ? $key : "{$prefix}.{$key}");
            if (\is_array($value) && $value !== [] && !self::isScalarList($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = self::text($value);
            }
        }

        return $flat;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isScalarList(array $value): bool
    {
        if (!array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (\is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i'),
            \is_bool($value) => $value ? 'true' : 'false',
            \is_array($value) => '[' . implode(', ', array_map(self::text(...), $value)) . ']',
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
