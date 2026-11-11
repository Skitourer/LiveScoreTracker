<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Two-factor recovery codes: 10 single-use codes per admin (spec § 19.1).
 * Only hashes are stored; the codes are shown once.
 */
final class RecoveryCodeRepository
{
    public const COUNT = 10;
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789'; // no ambiguous characters

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * Replaces the user's codes with new ones and returns them in clear text.
     *
     * @return list<string> e.g. "k7mq-3x9p"
     */
    public function regenerate(int $userId): array
    {
        $this->deleteAll($userId);
        $codes = [];
        $insert = $this->pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (:u, :h, :c)');
        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = self::randomCode();
            $codes[] = $code;
            $insert->execute(['u' => $userId, 'h' => password_hash(self::normalize($code), PASSWORD_DEFAULT), 'c' => $this->clock->stamp()]);
        }

        return $codes;
    }

    /**
     * Consumes a matching unused code. Returns false if none matches.
     */
    public function consume(int $userId, string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT id, code_hash FROM recovery_codes WHERE user_id = :u AND used_at IS NULL');
        $statement->execute(['u' => $userId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (\is_array($row) && \is_string($row['code_hash'] ?? null) && password_verify(self::normalize($code), $row['code_hash'])) {
                $this->pdo->prepare('UPDATE recovery_codes SET used_at = :now WHERE id = :id')
                    ->execute(['now' => $this->clock->stamp(), 'id' => $row['id']]);

                return true;
            }
        }

        return false;
    }

    public function remaining(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = :u AND used_at IS NULL');
        $statement->execute(['u' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function deleteAll(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = :u')->execute(['u' => $userId]);
    }

    public static function looksLikeRecoveryCode(string $input): bool
    {
        return \strlen(self::normalize($input)) === 8 && !ctype_digit(self::normalize($input));
    }

    private static function normalize(string $code): string
    {
        return strtolower(str_replace(['-', ' '], '', trim($code)));
    }

    private static function randomCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; ++$i) {
            $chars .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }
}
