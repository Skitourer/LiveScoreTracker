<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Journal of sensitive actions (spec § 11.6, § 19.2).
 *
 * Never store passwords, codes or tokens in the details.
 */
final class AuditLog
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, scalar|null> $details
     */
    public function record(
        string $action,
        ?User $actor = null,
        array $details = [],
        ?string $ip = null,
        ?string $deviceId = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (occurred_at, user_id, username, role, action, details, ip, device_id)
             VALUES (:at, :uid, :username, :role, :action, :details, :ip, :device)',
        );
        $statement->execute([
            'at' => $this->clock->stamp(),
            'uid' => $actor?->id,
            'username' => $actor?->username,
            'role' => $actor?->role->value,
            'action' => $action,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $ip,
            'device' => $deviceId,
        ]);
    }

    /**
     * Most recent entries first.
     *
     * @return list<array{id: int, occurred_at: string, username: string|null, role: string|null, action: string, details: array<string, mixed>, ip: string|null, device_id: string|null}>
     */
    public function page(int $page = 1, ?string $action = null): array
    {
        $sql = 'SELECT * FROM audit_log' . ($action !== null ? ' WHERE action = :action' : '')
            . ' ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (max(1, $page) - 1) * self::PAGE_SIZE;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($action !== null ? ['action' => $action] : []);

        $entries = [];
        foreach (Row::all($statement) as $row) {
            $details = json_decode(Row::string($row, 'details'), true);
            $typedDetails = [];
            foreach (\is_array($details) ? $details : [] as $key => $value) {
                $typedDetails[(string) $key] = $value;
            }
            $entries[] = [
                'id' => Row::int($row, 'id'),
                'occurred_at' => Row::string($row, 'occurred_at'),
                'username' => Row::nullableString($row, 'username'),
                'role' => Row::nullableString($row, 'role'),
                'action' => Row::string($row, 'action'),
                'details' => $typedDetails,
                'ip' => Row::nullableString($row, 'ip'),
                'device_id' => Row::nullableString($row, 'device_id'),
            ];
        }

        return $entries;
    }

    public function count(?string $action = null): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log' . ($action !== null ? ' WHERE action = :action' : ''));
        $statement->execute($action !== null ? ['action' => $action] : []);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT action FROM audit_log ORDER BY action');

        return $statement === false ? [] : array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
