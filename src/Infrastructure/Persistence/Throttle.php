<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Progressive blocking after repeated failures (spec § 19.1).
 *
 * Once `maxFailures` failures are reached, the key is blocked for 1 minute,
 * then each new failure doubles the delay (2, 4, 8… minutes, up to one hour).
 * A success clears the key.
 */
final class Throttle
{
    private const MAX_BLOCK_MINUTES = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * Seconds left before the key may try again (0 when allowed).
     */
    public function secondsBlocked(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT blocked_until FROM throttle WHERE throttle_key = :k');
        $statement->execute(['k' => self::key($key)]);
        $until = $statement->fetchColumn();
        if (!\is_string($until)) {
            return 0;
        }
        $blockedUntil = new DateTimeImmutable($until, new DateTimeZone('UTC'));

        return max(0, $blockedUntil->getTimestamp() - $this->clock->now()->getTimestamp());
    }

    public function recordFailure(string $key, int $maxFailures): void
    {
        $k = self::key($key);
        $statement = $this->pdo->prepare('SELECT failures FROM throttle WHERE throttle_key = :k');
        $statement->execute(['k' => $k]);
        $current = $statement->fetchColumn();
        $failures = ($current === false ? 0 : (int) $current) + 1;

        $blockedUntil = null;
        if ($failures >= $maxFailures) {
            $minutes = min(self::MAX_BLOCK_MINUTES, 2 ** min(6, $failures - $maxFailures));
            $blockedUntil = $this->clock->stampIn("+{$minutes} minutes");
        }

        $params = ['k' => $k, 'f' => $failures, 'b' => $blockedUntil, 'now' => $this->clock->stamp()];
        $sql = $current === false
            ? 'INSERT INTO throttle (throttle_key, failures, blocked_until, updated_at) VALUES (:k, :f, :b, :now)'
            : 'UPDATE throttle SET failures = :f, blocked_until = :b, updated_at = :now WHERE throttle_key = :k';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function clear(string $key): void
    {
        $this->pdo->prepare('DELETE FROM throttle WHERE throttle_key = :k')->execute(['k' => self::key($key)]);
    }

    /**
     * Forgets failures older than a day.
     */
    public function prune(): void
    {
        $this->pdo->prepare('DELETE FROM throttle WHERE updated_at < :limit')->execute(['limit' => $this->clock->stampIn('-1 day')]);
    }

    private static function key(string $key): string
    {
        return \strlen($key) <= 191 ? $key : hash('sha256', $key);
    }
}
