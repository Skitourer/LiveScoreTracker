<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Registration\AttendanceMark;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Security\Phase;
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
     * @param array{q?: string, names_only?: bool, statuses?: list<RegistrationStatus>, school?: string, level?: string, gender?: string, volunteer?: bool} $filters
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
        $namesOnly = $filters['names_only'] ?? false;

        return array_values(array_filter($registrations, static function (Registration $r) use ($query, $namesOnly): bool {
            $c = $r->child;
            if ((string) $r->bib === $query) {
                return true;
            }
            $texts = [$c->lastName . $c->firstName, $c->firstName . $c->lastName];
            if (!$namesOnly) {
                array_push($texts, $c->school, $c->guardianName, $c->guardianEmail);
            }
            foreach ($texts as $text) {
                if (str_contains(TextCleaner::key($text), $query)) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function findByBib(int $bib): ?Registration
    {
        $statement = $this->pdo->prepare('SELECT * FROM registrations WHERE bib = :bib');
        $statement->execute(['bib' => $bib]);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * Ticks or unticks a reception box (spec § 6.2), timestamped and kept in the history.
     *
     * @return bool false when the box was already in that state (another phone was quicker)
     */
    public function setAttendance(int $id, AttendanceMark $mark, bool $done, ?User $actor): bool
    {
        $column = $mark->column();
        $statement = $this->pdo->prepare(
            "UPDATE registrations SET {$column} = :at WHERE id = :id AND {$column} IS " . ($done ? 'NULL' : 'NOT NULL'),
        );
        $statement->execute(['at' => $done ? $this->clock->stamp() : null, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            return false;
        }
        $this->addHistory($id, 'attendance', [$mark->value => [!$done, $done]], $actor);

        return true;
    }

    /**
     * Reception counters (spec § 6.2), among registered children.
     *
     * @return array{registered: int, present: int, bib_returned: int, gift_given: int}
     */
    public function attendanceCounts(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS registered, COUNT(checked_in_at) AS present,
                COUNT(bib_returned_at) AS bib_returned, COUNT(gift_given_at) AS gift_given
             FROM registrations WHERE status = :s',
        );
        $statement->execute(['s' => RegistrationStatus::Registered->value]);
        $row = Row::all($statement)[0] ?? [];

        return [
            'registered' => Row::int($row, 'registered'),
            'present' => Row::int($row, 'present'),
            'bib_returned' => Row::int($row, 'bib_returned'),
            'gift_given' => Row::int($row, 'gift_given'),
        ];
    }

    /**
     * Registrations created or changed since a moment (UTC 'Y-m-d H:i:s', included),
     * to know whether printed lists are still up to date.
     */
    public function countChangedSince(string $at): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM registrations WHERE updated_at >= :at OR created_at >= :at2');
        $statement->execute(['at' => $at, 'at2' => $at]);

        return (int) $statement->fetchColumn();
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
     * Moves a registration to another status; becoming "registered" gives a bib.
     *
     * Bibs (spec § 5.6): during preparation, a withdrawn child gives their bib back,
     * so the limited stock of paper bibs is used up in order. Once the competition
     * has started, a withdrawn child keeps their bib: it is never given to anyone
     * else, and they find it again if registered back.
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

        if ($status === RegistrationStatus::Withdrawn && $current->bib !== null && $this->appState->phase() === Phase::Preparation) {
            $this->pdo->prepare('UPDATE registrations SET bib = NULL WHERE id = :id')->execute(['id' => $id]);
            $this->addHistory($id, 'bib_freed', ['bib' => [$current->bib, null]], null);
        }
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
     *
     * The bib is the lowest number not held by any registration, from the first bib
     * of the configuration. A withdrawn child still holds their bib once the
     * competition has started, so a bib worn on the day is never given again.
     */
    private function markRegistered(int $id, int $firstBib): void
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->pdo->beginTransaction();
            try {
                $next = $this->lowestFreeBib($firstBib);

                $update = $this->pdo->prepare(
                    'UPDATE registrations SET bib = :bib, registered_at = :now, updated_at = :now2 WHERE id = :id AND bib IS NULL',
                );
                $now = $this->clock->stamp();
                $update->execute(['bib' => $next, 'now' => $now, 'now2' => $now, 'id' => $id]);
                if ($update->rowCount() > 0) {
                    $this->addHistory($id, 'bib', ['bib' => [null, $next]], null);
                } else {
                    // Already numbered (registered back during the competition): keep the bib.
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

    private function lowestFreeBib(int $firstBib): int
    {
        $statement = $this->pdo->prepare('SELECT bib FROM registrations WHERE bib >= :first ORDER BY bib');
        $statement->execute(['first' => $firstBib]);
        $next = $firstBib;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $bib) {
            if ((int) $bib !== $next) {
                break;
            }
            ++$next;
        }

        return $next;
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
            checkedInAt: Row::nullableString($row, 'checked_in_at'),
            bibReturnedAt: Row::nullableString($row, 'bib_returned_at'),
            giftGivenAt: Row::nullableString($row, 'gift_given_at'),
        );
    }
}
