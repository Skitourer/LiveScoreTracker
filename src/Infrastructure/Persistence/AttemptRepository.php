<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Infrastructure\Clock;
use PDO;
use PDOException;

/**
 * Attempts entered at the workshops, with every version kept (spec § 8.3).
 *
 * Each entry carries an id made when the form (or, later, the phone) prepared
 * it: sending the same entry twice records it once (spec § 8.8).
 */
final class AttemptRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, int> $modifiers
     * @param list<string>       $warnings
     *
     * @return int id of the stored attempt (the existing one if this entry was already received)
     */
    public function record(
        string $clientId,
        int $registrationId,
        int $bib,
        string $variantId,
        ?int $measured,
        array $modifiers,
        int $modifierTotal,
        array $warnings,
        Author $author,
        ?int $originalId = null,
    ): int {
        $existing = $this->idForClient($clientId);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO attempts (client_id, original_id, registration_id, bib, variant_id, measured, modifiers, modifier_total,
                    warnings, entered_at, user_id, username, referee_name, device_id)
                 VALUES (:client, :original, :reg, :bib, :variant, :measured, :modifiers, :total,
                    :warnings, :at, :uid, :username, :referee, :device)',
            )->execute([
                'client' => $clientId,
                'original' => $originalId,
                'reg' => $registrationId,
                'bib' => $bib,
                'variant' => $variantId,
                'measured' => $measured,
                'modifiers' => $modifiers === [] ? null : json_encode($modifiers, JSON_THROW_ON_ERROR),
                'total' => $modifierTotal,
                'warnings' => $warnings === [] ? null : json_encode($warnings, JSON_THROW_ON_ERROR),
                'at' => $this->clock->stamp(),
                'uid' => $author->userId,
                'username' => $author->username,
                'referee' => $author->refereeName,
                'device' => $author->deviceId,
            ]);
        } catch (PDOException $exception) {
            // The same entry arrived twice at the same moment: keep the first one.
            return $this->idForClient($clientId) ?? throw $exception;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Records a corrected version of an attempt and closes the previous one.
     *
     * @param array<string, int> $modifiers
     * @param list<string>       $warnings
     */
    public function correct(StoredAttempt $previous, string $clientId, ?int $measured, array $modifiers, int $modifierTotal, array $warnings, Author $author): int
    {
        if ($this->idForClient($clientId) !== null || !$previous->isCurrent()) {
            return $this->idForClient($clientId) ?? $previous->id;
        }
        $this->pdo->beginTransaction();
        try {
            $id = $this->record($clientId, $previous->registrationId, $previous->bib, $previous->variantId, $measured, $modifiers, $modifierTotal, $warnings, $author, $previous->originalId);
            $this->pdo->prepare('UPDATE attempts SET replaced_at = :at WHERE id = :id')->execute(['at' => $this->clock->stamp(), 'id' => $previous->id]);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $id;
    }

    /**
     * Marks an attempt entered by mistake as deleted (it stays in the history).
     */
    public function delete(StoredAttempt $attempt, Author $author): void
    {
        $this->pdo->prepare('UPDATE attempts SET deleted_at = :at, deleted_by = :by WHERE id = :id AND deleted_at IS NULL AND replaced_at IS NULL')
            ->execute(['at' => $this->clock->stamp(), 'by' => $author->refereeName ?? $author->username, 'id' => $attempt->id]);
    }

    public function find(int $id): ?StoredAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM attempts WHERE id = :id');
        $statement->execute(['id' => $id]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Every version of a child's attempts on a variant, in the order the attempts were made.
     *
     * @return list<StoredAttempt>
     */
    public function history(int $registrationId, string $variantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM attempts WHERE registration_id = :reg AND variant_id = :variant ORDER BY COALESCE(original_id, id), id',
        );
        $statement->execute(['reg' => $registrationId, 'variant' => $variantId]);

        return array_map(self::hydrate(...), Row::all($statement));
    }

    /**
     * Current attempts of a child on a variant, in the order they were made.
     *
     * @return list<StoredAttempt>
     */
    public function current(int $registrationId, string $variantId): array
    {
        return array_values(array_filter($this->history($registrationId, $variantId), static fn(StoredAttempt $a): bool => $a->isCurrent()));
    }

    /**
     * Current attempts on some variants, grouped by child.
     *
     * @param list<string> $variantIds
     *
     * @return array<int, list<StoredAttempt>> registration id => attempts
     */
    public function currentByChild(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach ($variantIds as $i => $variantId) {
            $names[] = ':v' . $i;
            $params['v' . $i] = $variantId;
        }
        $statement = $this->pdo->prepare(
            'SELECT * FROM attempts WHERE replaced_at IS NULL AND deleted_at IS NULL AND variant_id IN (' . implode(', ', $names) . ')
             ORDER BY COALESCE(original_id, id), id',
        );
        $statement->execute($params);
        $byChild = [];
        foreach (Row::all($statement) as $row) {
            $attempt = self::hydrate($row);
            $byChild[$attempt->registrationId][] = $attempt;
        }

        return $byChild;
    }

    private function idForClient(string $clientId): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM attempts WHERE client_id = :c');
        $statement->execute(['c' => $clientId]);
        $id = $statement->fetchColumn();

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StoredAttempt
    {
        $id = Row::int($row, 'id');
        $measured = Row::nullableString($row, 'measured');
        $original = Row::nullableString($row, 'original_id');
        $modifiers = [];
        foreach (self::json(Row::nullableString($row, 'modifiers')) as $key => $count) {
            if (\is_int($count)) {
                $modifiers[(string) $key] = $count;
            }
        }

        return new StoredAttempt(
            id: $id,
            originalId: $original === null ? $id : (int) $original,
            registrationId: Row::int($row, 'registration_id'),
            bib: Row::int($row, 'bib'),
            variantId: Row::string($row, 'variant_id'),
            measured: $measured === null ? null : (int) $measured,
            modifiers: $modifiers,
            modifierTotal: Row::int($row, 'modifier_total'),
            warnings: array_values(array_filter(self::json(Row::nullableString($row, 'warnings')), \is_string(...))),
            enteredAt: Row::string($row, 'entered_at'),
            username: Row::nullableString($row, 'username'),
            refereeName: Row::nullableString($row, 'referee_name'),
            replacedAt: Row::nullableString($row, 'replaced_at'),
            deletedAt: Row::nullableString($row, 'deleted_at'),
            deletedBy: Row::nullableString($row, 'deleted_by'),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function json(?string $value): array
    {
        $decoded = $value === null ? null : json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
