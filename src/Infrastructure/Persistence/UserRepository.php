<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Security\PasswordPolicy;
use LiveScoreTracker\Domain\Security\Role;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Accounts storage.
 */
final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    public function find(int $id): ?User
    {
        return $this->one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public function findByUsername(string $username): ?User
    {
        return $this->one('SELECT * FROM users WHERE username = :u', ['u' => self::normalize($username)]);
    }

    /**
     * Individual account with this email (used for "forgot password").
     */
    public function findByEmail(string $email): ?User
    {
        return $this->one(
            'SELECT * FROM users WHERE LOWER(email) = :e AND role <> :referee',
            ['e' => mb_strtolower(trim($email)), 'referee' => Role::Referee->value],
        );
    }

    /**
     * @return list<User> admins first, then organizers, then the referee account
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            "SELECT * FROM users ORDER BY CASE role WHEN 'admin' THEN 0 WHEN 'organizer' THEN 1 ELSE 2 END, display_name",
        );

        return $statement === false ? [] : array_map(self::hydrate(...), Row::all($statement));
    }

    public function countActiveAdmins(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND disabled_at IS NULL');
        $statement->execute(['role' => Role::Admin->value]);

        return (int) $statement->fetchColumn();
    }

    public function refereeAccount(): ?User
    {
        return $this->one('SELECT * FROM users WHERE role = :role ORDER BY id LIMIT 1', ['role' => Role::Referee->value]);
    }

    public function usernameExists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function create(
        string $username,
        string $displayName,
        ?string $email,
        Role $role,
        string $password,
        bool $mustChangePassword,
    ): int {
        $now = $this->clock->stamp();
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, display_name, email, role, password_hash, must_change_password, created_at, updated_at)
             VALUES (:username, :display_name, :email, :role, :hash, :must_change, :now, :now2)',
        );
        $statement->execute([
            'username' => self::normalize($username),
            'display_name' => trim($displayName),
            'email' => self::nullableEmail($email),
            'role' => $role->value,
            'hash' => PasswordPolicy::hash($password),
            'must_change' => $mustChangePassword ? 1 : 0,
            'now' => $now,
            'now2' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateProfile(int $id, string $displayName, ?string $email): void
    {
        $this->update($id, ['display_name' => trim($displayName), 'email' => self::nullableEmail($email)]);
    }

    /**
     * Sets a new password and logs the account out everywhere.
     */
    public function setPassword(int $id, string $password, bool $mustChange): void
    {
        $this->update($id, [
            'password_hash' => PasswordPolicy::hash($password),
            'must_change_password' => $mustChange ? 1 : 0,
        ]);
        $this->bumpSessionVersion($id);
        $this->revokeTrustedDevices($id);
    }

    /**
     * Upgrades the stored hash of an unchanged password (stronger algorithm available).
     */
    public function setPasswordHashOnly(int $id, string $hash): void
    {
        $this->update($id, ['password_hash' => $hash]);
    }

    public function setPendingTotpSecret(int $id, ?string $secret): void
    {
        $this->update($id, ['totp_secret' => $secret, 'totp_enabled_at' => null]);
        $this->revokeTrustedDevices($id);
    }

    public function enableTotp(int $id): void
    {
        $this->update($id, ['totp_enabled_at' => $this->clock->stamp()]);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $this->update($id, ['disabled_at' => $disabled ? $this->clock->stamp() : null]);
        if ($disabled) {
            $this->bumpSessionVersion($id);
            $this->revokeTrustedDevices($id);
        }
    }

    /**
     * Invalidates every session of the account ("disconnect all phones").
     */
    public function bumpSessionVersion(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET session_version = session_version + 1, updated_at = :now WHERE id = :id');
        $statement->execute(['now' => $this->clock->stamp(), 'id' => $id]);
    }

    public function touchLogin(int $id): void
    {
        $this->update($id, ['last_login_at' => $this->clock->stamp()]);
    }

    /**
     * Two-factor "trusted devices" end with any change of password, 2FA or status.
     */
    private function revokeTrustedDevices(int $id): void
    {
        $this->pdo->prepare('DELETE FROM trusted_devices WHERE user_id = :u')->execute(['u' => $id]);
    }

    public static function normalize(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    /**
     * @param array<string, string|int|null> $fields
     */
    private function update(int $id, array $fields): void
    {
        $fields['updated_at'] = $this->clock->stamp();
        $assignments = implode(', ', array_map(static fn(string $f): string => "{$f} = :{$f}", array_keys($fields)));
        $statement = $this->pdo->prepare("UPDATE users SET {$assignments} WHERE id = :id");
        $statement->execute([...$fields, 'id' => $id]);
    }

    /**
     * @param array<string, string|int> $params
     */
    private function one(string $sql, array $params): ?User
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = Row::all($statement);

        return $rows === [] ? null : self::hydrate($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): User
    {
        $string = static fn(string $key): string => Row::string($row, $key);
        $nullable = static fn(string $key): ?string => Row::nullableString($row, $key);

        return new User(
            id: (int) $string('id'),
            username: $string('username'),
            displayName: $string('display_name'),
            email: $nullable('email'),
            role: Role::from($string('role')),
            passwordHash: $string('password_hash'),
            mustChangePassword: (int) $string('must_change_password') === 1,
            totpSecret: $nullable('totp_secret'),
            totpEnabled: $nullable('totp_enabled_at') !== null,
            sessionVersion: (int) $string('session_version'),
            disabled: $nullable('disabled_at') !== null,
            lastLoginAt: $nullable('last_login_at'),
        );
    }

    private static function nullableEmail(?string $email): ?string
    {
        $email = $email === null ? '' : trim($email);

        return $email === '' ? null : $email;
    }
}
