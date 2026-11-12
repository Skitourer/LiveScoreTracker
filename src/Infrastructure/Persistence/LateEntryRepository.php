<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;
use PDOException;

/**
 * Entries sent by a phone after the competition was closed (spec § 8.8):
 * kept aside and not counted until an admin or organizer accepts or refuses them.
 */
final class LateEntryRepository
{
    public const ACCEPTED = 'accepted';
    public const REFUSED = 'refused';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * Keeps an entry as sent (received twice: kept once).
     *
     * @param array<mixed> $payload the entry as sent by the phone
     */
    public function store(string $clientId, string $workshopId, int $bib, string $variantId, array $payload, Author $author): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO late_entries (client_id, workshop_id, bib, variant_id, payload, received_at, user_id, username, referee_name, device_id)
                 VALUES (:client, :workshop, :bib, :variant, :payload, :at, :uid, :username, :referee, :device)',
            )->execute([
                'client' => $clientId,
                'workshop' => $workshopId,
                'bib' => $bib,
                'variant' => $variantId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'at' => $this->clock->stamp(),
                'uid' => $author->userId,
                'username' => $author->username,
                'referee' => $author->refereeName,
                'device' => $author->deviceId,
            ]);
        } catch (PDOException $exception) {
            // The same entry arrived twice (unique id): keep the first one.
            if ($this->find($clientId) === null) {
                throw $exception;
            }
        }
    }

    /**
     * Entries still waiting for a decision, oldest first.
     *
     * @return list<LateEntry>
     */
    public function pending(): array
    {
        $statement = $this->pdo->query('SELECT * FROM late_entries WHERE decision IS NULL ORDER BY id');

        return $statement === false ? [] : array_map(self::hydrate(...), Row::all($statement));
    }

    public function countPending(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM late_entries WHERE decision IS NULL');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function findById(int $id): ?LateEntry
    {
        $statement = $this->pdo->prepare('SELECT * FROM late_entries WHERE id = :id');
        $statement->execute(['id' => $id]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    public function find(string $clientId): ?LateEntry
    {
        $statement = $this->pdo->prepare('SELECT * FROM late_entries WHERE client_id = :c');
        $statement->execute(['c' => $clientId]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Records the decision; false when it was already decided.
     */
    public function decide(LateEntry $entry, string $decision, string $by): bool
    {
        $statement = $this->pdo->prepare('UPDATE late_entries SET decision = :d, decided_at = :at, decided_by = :by WHERE id = :id AND decision IS NULL');
        $statement->execute(['d' => $decision, 'at' => $this->clock->stamp(), 'by' => $by, 'id' => $entry->id]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): LateEntry
    {
        $payload = json_decode(Row::string($row, 'payload'), true);
        $userId = Row::nullableString($row, 'user_id');

        return new LateEntry(
            id: Row::int($row, 'id'),
            clientId: Row::string($row, 'client_id'),
            workshopId: Row::string($row, 'workshop_id'),
            bib: Row::int($row, 'bib'),
            variantId: Row::string($row, 'variant_id'),
            payload: \is_array($payload) ? $payload : [],
            receivedAt: Row::string($row, 'received_at'),
            author: new Author(
                $userId === null ? null : (int) $userId,
                Row::nullableString($row, 'username'),
                Row::nullableString($row, 'referee_name'),
                Row::nullableString($row, 'device_id'),
            ),
            decision: Row::nullableString($row, 'decision'),
        );
    }
}
