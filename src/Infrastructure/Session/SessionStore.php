<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Session;

use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\Row;
use PDO;

/**
 * Loads and saves sessions in the `sessions` table.
 *
 * A session expires after a period of inactivity (default 4 h, a competition
 * morning) and in any case after 16 h.
 */
final class SessionStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly int $idleMinutes = 240,
        private readonly int $maxHours = 16,
    ) {}

    public function load(?string $token, string $deviceId): Session
    {
        if ($token === null || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return Session::start($deviceId);
        }

        $statement = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id AND expires_at > :now');
        $statement->execute(['id' => self::hash($token), 'now' => $this->clock->stamp()]);
        $raw = $statement->fetchColumn();
        $data = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($data)) {
            return Session::start($deviceId);
        }

        $loggedInAt = $data['logged_in_at'] ?? null;
        if (\is_int($loggedInAt) && $loggedInAt < $this->clock->now()->getTimestamp() - $this->maxHours * 3600) {
            $this->delete($token);

            return Session::start($deviceId);
        }

        /** @var array<string, mixed> $data */
        return new Session($token, $data, false, $deviceId);
    }

    /**
     * Saves the session. Anonymous sessions without data are not stored.
     *
     * @return bool whether the browser must keep (or receive) the session cookie
     */
    public function save(Session $session, ?string $ip, ?string $userAgent): bool
    {
        if ($session->previousToken() !== null) {
            $this->delete($session->previousToken());
        }
        if ($session->isDestroyed()) {
            if (!$session->isNew()) {
                $this->delete($session->token());
            }

            return false;
        }
        if ($session->isNew() && $session->all() === []) {
            return false;
        }

        $id = self::hash($session->token());
        $now = $this->clock->stamp();
        $expires = $this->clock->stampIn("+{$this->idleMinutes} minutes");
        $data = json_encode($session->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $update = $this->pdo->prepare(
            'UPDATE sessions SET data = :data, user_id = :uid, last_seen_at = :now, expires_at = :exp WHERE id = :id',
        );
        $update->execute(['data' => $data, 'uid' => $session->userId(), 'now' => $now, 'exp' => $expires, 'id' => $id]);

        if ($update->rowCount() === 0) {
            $this->pdo->prepare(
                'INSERT INTO sessions (id, user_id, data, device_id, ip, user_agent, created_at, last_seen_at, expires_at)
                 VALUES (:id, :uid, :data, :device, :ip, :ua, :created, :now, :exp)',
            )->execute([
                'id' => $id,
                'uid' => $session->userId(),
                'data' => $data,
                'device' => $session->deviceId,
                'ip' => $ip,
                'ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'created' => $now,
                'now' => $now,
                'exp' => $expires,
            ]);
        }

        if (random_int(1, 100) === 1) {
            $this->pruneExpired();
        }

        return true;
    }

    /**
     * Active sessions of an account, most recent first (connected phones).
     *
     * @return list<array{id: string, device_id: string|null, ip: string|null, user_agent: string|null, last_seen_at: string, referee_name: string|null}>
     */
    public function activeForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, data, device_id, ip, user_agent, last_seen_at FROM sessions
             WHERE user_id = :u AND expires_at > :now ORDER BY last_seen_at DESC',
        );
        $statement->execute(['u' => $userId, 'now' => $this->clock->stamp()]);

        $sessions = [];
        foreach (Row::all($statement) as $row) {
            $data = json_decode(Row::string($row, 'data'), true);
            $name = \is_array($data) && \is_string($data['referee_name'] ?? null) ? $data['referee_name'] : null;
            $sessions[] = [
                'id' => Row::string($row, 'id'),
                'referee_name' => $name,
                'device_id' => Row::nullableString($row, 'device_id'),
                'ip' => Row::nullableString($row, 'ip'),
                'user_agent' => Row::nullableString($row, 'user_agent'),
                'last_seen_at' => Row::string($row, 'last_seen_at'),
            ];
        }

        return $sessions;
    }

    /**
     * Ends one stored session (identified by its stored id, not by the browser's token).
     */
    public function deleteStored(string $storedId, int $userId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id AND user_id = :u');
        $statement->execute(['id' => $storedId, 'u' => $userId]);

        return $statement->rowCount() > 0;
    }

    public function deleteForUser(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = :u')->execute(['u' => $userId]);
    }

    public function pruneExpired(): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE expires_at <= :now')->execute(['now' => $this->clock->stamp()]);
    }

    private function delete(string $token): void
    {
        $this->pdo->prepare('DELETE FROM sessions WHERE id = :id')->execute(['id' => self::hash($token)]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
