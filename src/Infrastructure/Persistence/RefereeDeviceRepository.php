<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Phones used with the shared referee account (spec § 19.1).
 *
 * A phone that logs in again finds its referee's first name; the admin can
 * block a phone for good. The phone is identified by its device cookie, so
 * this stops casual misuse; changing the referee password stops everyone.
 */
final class RefereeDeviceRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{device_id: string, referee_name: string, user_agent: string|null, first_seen_at: string, last_seen_at: string, blocked: bool, blocked_by: string|null}|null
     */
    public function find(string $deviceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM referee_devices WHERE device_id = :d');
        $statement->execute(['d' => $deviceId]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::shape($rows[0]);
    }

    public function isBlocked(string $deviceId): bool
    {
        return ($this->find($deviceId)['blocked'] ?? false) === true;
    }

    /**
     * Records (or renames) the phone and its referee.
     */
    public function remember(string $deviceId, string $name, ?string $userAgent): void
    {
        $now = $this->clock->stamp();
        $params = ['d' => $deviceId, 'n' => $name, 'ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 255), 'now' => $now];
        $update = $this->pdo->prepare('UPDATE referee_devices SET referee_name = :n, user_agent = :ua, last_seen_at = :now WHERE device_id = :d');
        $update->execute($params);
        if ($update->rowCount() === 0) {
            $this->pdo->prepare(
                'INSERT INTO referee_devices (device_id, referee_name, user_agent, first_seen_at, last_seen_at)
                 VALUES (:d, :n, :ua, :now, :now2)',
            )->execute([...$params, 'now2' => $now]);
        }
    }

    public function touch(string $deviceId): void
    {
        $this->pdo->prepare('UPDATE referee_devices SET last_seen_at = :now WHERE device_id = :d')
            ->execute(['now' => $this->clock->stamp(), 'd' => $deviceId]);
    }

    public function block(string $deviceId, string $by): void
    {
        $this->pdo->prepare('UPDATE referee_devices SET blocked_at = :now, blocked_by = :by WHERE device_id = :d')
            ->execute(['now' => $this->clock->stamp(), 'by' => $by, 'd' => $deviceId]);
    }

    public function unblock(string $deviceId): void
    {
        $this->pdo->prepare('UPDATE referee_devices SET blocked_at = NULL, blocked_by = NULL WHERE device_id = :d')
            ->execute(['d' => $deviceId]);
    }

    public function forget(string $deviceId): void
    {
        $this->pdo->prepare('DELETE FROM referee_devices WHERE device_id = :d AND blocked_at IS NULL')->execute(['d' => $deviceId]);
    }

    /**
     * @return list<array{device_id: string, referee_name: string, user_agent: string|null, first_seen_at: string, last_seen_at: string, blocked: bool, blocked_by: string|null}>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM referee_devices ORDER BY CASE WHEN blocked_at IS NULL THEN 0 ELSE 1 END, last_seen_at DESC');

        return $statement === false ? [] : array_map(self::shape(...), Row::all($statement));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{device_id: string, referee_name: string, user_agent: string|null, first_seen_at: string, last_seen_at: string, blocked: bool, blocked_by: string|null}
     */
    private static function shape(array $row): array
    {
        return [
            'device_id' => Row::string($row, 'device_id'),
            'referee_name' => Row::string($row, 'referee_name'),
            'user_agent' => Row::nullableString($row, 'user_agent'),
            'first_seen_at' => Row::string($row, 'first_seen_at'),
            'last_seen_at' => Row::string($row, 'last_seen_at'),
            'blocked' => Row::nullableString($row, 'blocked_at') !== null,
            'blocked_by' => Row::nullableString($row, 'blocked_by'),
        ];
    }
}
