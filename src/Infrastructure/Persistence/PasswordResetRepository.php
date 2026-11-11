<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Single-use password reset links, valid one hour (spec § 19.1).
 * Only a hash of the token is stored.
 */
final class PasswordResetRepository
{
    public const VALIDITY = '+1 hour';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * Creates a token for the user and returns it (to put in the email link).
     */
    public function create(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:u, :h, :e, :c)',
        )->execute([
            'u' => $userId,
            'h' => hash('sha256', $token),
            'e' => $this->clock->stampIn(self::VALIDITY),
            'c' => $this->clock->stamp(),
        ]);

        return $token;
    }

    /**
     * User id of a valid (unused, unexpired) token, or null.
     */
    public function validUserId(string $token): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
        );
        $statement->execute(['h' => hash('sha256', $token), 'now' => $this->clock->stamp()]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (int) $userId;
    }

    /**
     * Marks every token of the user as used (after a successful reset).
     */
    public function consumeAll(int $userId): void
    {
        $this->pdo->prepare('UPDATE password_resets SET used_at = :now WHERE user_id = :u AND used_at IS NULL')
            ->execute(['now' => $this->clock->stamp(), 'u' => $userId]);
    }
}
