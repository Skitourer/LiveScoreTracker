<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Devices where an admin chose "trust this device for 30 days" after a two-factor login.
 *
 * The browser keeps a random token; the database keeps its hash, the account and the
 * device id. The code is asked again on another device, after 30 days, or once trust
 * is revoked (password change, 2FA reset, account disabled, or by hand).
 */
final class TrustedDeviceRepository
{
    public const LIFETIME_DAYS = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @return string the token to store in the browser cookie
     */
    public function trust(int $userId, string $deviceId, ?string $userAgent): string
    {
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->stamp();
        $this->pdo->prepare(
            'INSERT INTO trusted_devices (user_id, token_hash, device_id, user_agent, created_at, last_used_at, expires_at)
             VALUES (:u, :h, :d, :ua, :now, :now2, :exp)',
        )->execute([
            'u' => $userId,
            'h' => hash('sha256', $token),
            'd' => $deviceId,
            'ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'now' => $now,
            'now2' => $now,
            'exp' => $this->clock->stampIn('+' . self::LIFETIME_DAYS . ' days'),
        ]);

        return $token;
    }

    /**
     * Whether the token proves this device is trusted for this account (and refreshes its last use).
     */
    public function isTrusted(int $userId, string $deviceId, ?string $token): bool
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return false;
        }
        $statement = $this->pdo->prepare(
            'SELECT id FROM trusted_devices WHERE token_hash = :h AND user_id = :u AND device_id = :d AND expires_at > :now',
        );
        $statement->execute(['h' => hash('sha256', $token), 'u' => $userId, 'd' => $deviceId, 'now' => $this->clock->stamp()]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->pdo->prepare('UPDATE trusted_devices SET last_used_at = :now WHERE id = :id')
            ->execute(['now' => $this->clock->stamp(), 'id' => $id]);

        return true;
    }

    /**
     * @return list<array{id: int, device_id: string, user_agent: string|null, created_at: string, last_used_at: string, expires_at: string}>
     */
    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM trusted_devices WHERE user_id = :u AND expires_at > :now ORDER BY last_used_at DESC',
        );
        $statement->execute(['u' => $userId, 'now' => $this->clock->stamp()]);
        $devices = [];
        foreach (Row::all($statement) as $row) {
            $devices[] = [
                'id' => Row::int($row, 'id'),
                'device_id' => Row::string($row, 'device_id'),
                'user_agent' => Row::nullableString($row, 'user_agent'),
                'created_at' => Row::string($row, 'created_at'),
                'last_used_at' => Row::string($row, 'last_used_at'),
                'expires_at' => Row::string($row, 'expires_at'),
            ];
        }

        return $devices;
    }

    public function revoke(int $userId, int $id): void
    {
        $this->pdo->prepare('DELETE FROM trusted_devices WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
    }

    public function revokeAll(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute(['u' => $userId]);
    }
}
