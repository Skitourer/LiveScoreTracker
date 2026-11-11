<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Clock;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Registrations storage, bib numbering (spec § 5.6) and change history (spec § 5.9).
 */
final class RegistrationRepository
{
    /** Highest bib ever given in this edition (bibs are never given twice). */
    private const LAST_BIB = 'last_bib';

    private const ACTIVE = [RegistrationStatus::PendingVerification, RegistrationStatus::Registered, RegistrationStatus::Waitlisted];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly AppState $appState,
    ) {}

    /**
     * Stores a new registration; a registered child gets the next bib at once.
     */
    public function create(ChildData $child, RegistrationStatus $status, RegistrationSource $source, ?User $actor, int $firstBib): int
    {
        $now = $this->clock->stamp();
        $this->pdo->prepare(
            'INSERT INTO registrations (status, source, last_name, first_name, birth_date, gender, school, level,
                guardian_name, guardian_phone, guardian_email, volunteer, match_key, created_by, created_at, updated_at)
             VALUES (:status, :source, :last_name, :first_name, :birth_date, :gender, :school, :level,
                :guardian_name, :guardian_phone, :guardian_email, :volunteer, :match_key, :created_by, :created_at, :updated_at)',
        )->execute([
            ...self::childParams($child),
            'status' => $status->value,
            'source' => $source->value,
            'match_key' => $child->matchKey(),
            'created_by' => $actor?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->addHistory($id, 'created', ['status' => [null, $status->value], 'source' => [null, $source->value]], $actor);

        if ($status === RegistrationStatus::Registered) {
            $this->markRegistered($id, $firstBib);
        }

        return $id;
    }

    public function find(int $id): ?Registration
    {
        $statement = $this->pdo->prepare('SELECT * FROM registrations WHERE id = :id');
        $statement->execute(['id' => $id]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Active registration of the same child: same last name, first name and birth date (spec § 5.1).
     */
    public function findDuplicate(ChildData $child, ?int $exceptId = null): ?Registration
    {
        if ($child->birthDate === null) {
            return null;
        }
        foreach ($this->search(['statuses' => self::ACTIVE]) as $registration) {
            if ($registration->id !== $exceptId
                && $registration->child->birthDate === $child->birthDate
                && TextCleaner::key($registration->child->lastName) === TextCleaner::key($child->lastName)
                && TextCleaner::key($registration->child->firstName) === TextCleaner::key($child->firstName)) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Registrations with the same import key (last name + first name + level).
     *
     * @return list<Registration>
     */
    public function findByMatchKey(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM registrations WHERE match_key = :k ORDER BY id');
        $statement->execute(['k' => $key]);

        return array_map(self::hydrate(...), Row::all($statement));
    }

    /**
     * @param array{q?: string, statuses?: list<RegistrationStatus>, school?: string, level?: string, gender?: string, volunteer?: bool} $filters
     *
     * @return list<Registration> by bib, then children without a bib by registration date
     */
    public function search(array $filters = []): array
    {
        $where = [];
        $params = [];
        $statuses = $filters['statuses'] ?? [];
        if ($statuses !== []) {
            $names = [];
            foreach ($statuses as $i => $status) {
                $names[] = ':s' . $i;
                $params['s' . $i] = $status->value;
            }
            $where[] = 'status IN (' . implode(', ', $names) . ')';
        }
        foreach (['school', 'level', 'gender'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $where[] = "{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if (($filters['volunteer'] ?? false) === true) {
            $where[] = 'volunteer = 1';
        }
        $sql = 'SELECT * FROM registrations' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY CASE WHEN bib IS NULL THEN 1 ELSE 0 END, bib, id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $registrations = array_map(self::hydrate(...), Row::all($statement));

        $query = TextCleaner::key($filters['q'] ?? '');
        if ($query === '') {
            return $registrations;
        }

        // Free search on bib, names, school and guardian (small lists: done in PHP, accent-insensitive).
        return array_values(array_filter($registrations, static function (Registration $r) use ($query): bool {
            $c = $r->child;
            if ((string) $r->bib === $query) {
                return true;
            }
            foreach ([$c->lastName . $c->firstName, $c->firstName . $c->lastName, $c->school, $c->guardianName, $c->guardianEmail] as $text) {
                if (str_contains(TextCleaner::key($text), $query)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<string, int> status => number of registrations
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(array_map(static fn(RegistrationStatus $s): string => $s->value, RegistrationStatus::cases()), 0);
        $statement = $this->pdo->query('SELECT status, COUNT(*) AS n FROM registrations GROUP BY status');
        foreach ($statement === false ? [] : Row::all($statement) as $row) {
            $counts[Row::string($row, 'status')] = Row::int($row, 'n');
        }

        return $counts;
    }

    /**
     * Saves corrected fields and records what changed.
     *
     * @return array<string, array{0: mixed, 1: mixed}> changed fields => [old, new]
     */
    public function update(int $id, ChildData $child, ?User $actor): array
    {
        $current = $this->find($id) ?? throw new RuntimeException("Registration {$id} not found.");
        $changes = [];
        foreach ($child->toArray() as $field => $value) {
            $old = $current->child->toArray()[$field];
            if ($old !== $value) {
                $changes[$field] = [$old, $value];
            }
        }
        if ($changes === []) {
            return [];
        }

        $this->pdo->prepare(
            'UPDATE registrations SET last_name = :last_name, first_name = :first_name, birth_date = :birth_date,
                gender = :gender, school = :school, level = :level, guardian_name = :guardian_name,
                guardian_phone = :guardian_phone, guardian_email = :guardian_email, volunteer = :volunteer,
                match_key = :match_key, updated_at = :now WHERE id = :id',
        )->execute([...self::childParams($child), 'match_key' => $child->matchKey(), 'now' => $this->clock->stamp(), 'id' => $id]);
        $this->addHistory($id, 'updated', $changes, $actor);

        return $changes;
    }

    /**
     * Moves a registration to another status; becoming "registered" gives the next bib.
     */
    public function changeStatus(int $id, RegistrationStatus $status, ?User $actor, int $firstBib): void
    {
        $current = $this->find($id) ?? throw new RuntimeException("Registration {$id} not found.");
        if ($current->status === $status) {
            return;
        }
        $now = $this->clock->stamp();
        $extra = match ($status) {
            RegistrationStatus::Withdrawn => ', withdrawn_at = :at',
            RegistrationStatus::Registered, RegistrationStatus::Waitlisted => ', verified_at = COALESCE(verified_at, :at)',
            default => '',
        };
        $params = ['status' => $status->value, 'now' => $now, 'id' => $id];
        if ($extra !== '') {
            $params['at'] = $now;
        }
        $this->pdo->prepare("UPDATE registrations SET status = :status, updated_at = :now{$extra} WHERE id = :id")->execute($params);
        $this->addHistory($id, 'status', ['status' => [$current->status->value, $status->value]], $actor);

        if ($status === RegistrationStatus::Registered) {
            $this->markRegistered($id, $firstBib);
        }
    }

    /**
     * @return list<array{occurred_at: string, username: string|null, action: string, changes: array<string, mixed>}>
     */
    public function history(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM registration_history WHERE registration_id = :id ORDER BY id DESC');
        $statement->execute(['id' => $id]);
        $entries = [];
        foreach (Row::all($statement) as $row) {
            $changes = json_decode(Row::string($row, 'changes'), true);
            $typed = [];
            foreach (\is_array($changes) ? $changes : [] as $field => $values) {
                $typed[(string) $field] = $values;
            }
            $entries[] = [
                'occurred_at' => Row::string($row, 'occurred_at'),
                'username' => Row::nullableString($row, 'username'),
                'action' => Row::string($row, 'action'),
                'changes' => $typed,
            ];
        }

        return $entries;
    }

    /**
     * Gives the registration its bib (if it has none yet) and sets the registration date.
     * The next bib is above every bib ever given, so a number is never reused (spec § 5.6).
     */
    private function markRegistered(int $id, int $firstBib): void
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->query('SELECT MAX(bib) FROM registrations');
                $maxBib = $statement === false ? null : $statement->fetchColumn();
                $next = max((int) ($this->appState->get(self::LAST_BIB) ?? 0), \is_numeric($maxBib) ? (int) $maxBib : 0, $firstBib - 1) + 1;

                $update = $this->pdo->prepare(
                    'UPDATE registrations SET bib = :bib, registered_at = :now WHERE id = :id AND bib IS NULL',
                );
                $update->execute(['bib' => $next, 'now' => $this->clock->stamp(), 'id' => $id]);
                if ($update->rowCount() > 0) {
                    $this->appState->set(self::LAST_BIB, (string) $next);
                    $this->addHistory($id, 'bib', ['bib' => [null, $next]], null);
                } else {
                    // Already numbered (e.g. back from the waiting list after a mistake): keep the bib.
                    $this->pdo->prepare('UPDATE registrations SET registered_at = COALESCE(registered_at, :now) WHERE id = :id')
                        ->execute(['now' => $this->clock->stamp(), 'id' => $id]);
                }
                $this->pdo->commit();

                return;
            } catch (PDOException $exception) {
                $this->pdo->rollBack();
                // Two registrations numbered at the same instant: the unique index refused one; try again.
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     */
    private function addHistory(int $id, string $action, array $changes, ?User $actor): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_history (registration_id, occurred_at, user_id, username, action, changes)
             VALUES (:rid, :at, :uid, :username, :action, :changes)',
        )->execute([
            'rid' => $id,
            'at' => $this->clock->stamp(),
            'uid' => $actor?->id,
            'username' => $actor?->username,
            'action' => $action,
            'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array<string, string|int|null>
     */
    private static function childParams(ChildData $child): array
    {
        return [
            'last_name' => $child->lastName,
            'first_name' => $child->firstName,
            'birth_date' => $child->birthDate,
            'gender' => $child->gender,
            'school' => $child->school,
            'level' => $child->level,
            'guardian_name' => $child->guardianName,
            'guardian_phone' => $child->guardianPhone,
            'guardian_email' => $child->guardianEmail,
            'volunteer' => $child->volunteer ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Registration
    {
        $bib = Row::nullableString($row, 'bib');

        return new Registration(
            id: Row::int($row, 'id'),
            bib: $bib === null ? null : (int) $bib,
            status: RegistrationStatus::tryFrom(Row::string($row, 'status')) ?? RegistrationStatus::PendingVerification,
            source: RegistrationSource::tryFrom(Row::string($row, 'source')) ?? RegistrationSource::Manual,
            child: new ChildData(
                lastName: Row::string($row, 'last_name'),
                firstName: Row::string($row, 'first_name'),
                birthDate: Row::nullableString($row, 'birth_date'),
                gender: Row::string($row, 'gender'),
                school: Row::string($row, 'school'),
                level: Row::string($row, 'level'),
                guardianName: Row::string($row, 'guardian_name'),
                guardianPhone: Row::string($row, 'guardian_phone'),
                guardianEmail: Row::string($row, 'guardian_email'),
                volunteer: Row::int($row, 'volunteer') === 1,
            ),
            createdAt: Row::string($row, 'created_at'),
            registeredAt: Row::nullableString($row, 'registered_at'),
            withdrawnAt: Row::nullableString($row, 'withdrawn_at'),
        );
    }
}
