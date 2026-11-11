<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Domain\Security\Phase;
use PDO;

/**
 * Application-wide values kept in the `app_meta` table (current phase…).
 */
final class AppState
{
    private const PHASE = 'phase';

    public function __construct(private readonly PDO $pdo) {}

    public function phase(): Phase
    {
        return Phase::tryFrom($this->get(self::PHASE) ?? '') ?? Phase::Preparation;
    }

    public function setPhase(Phase $phase): void
    {
        $this->set(self::PHASE, $phase->value);
    }

    public function get(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT meta_value FROM app_meta WHERE meta_key = :k');
        $statement->execute(['k' => $key]);
        $value = $statement->fetchColumn();

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $exists = $this->get($key) !== null;
        $sql = $exists
            ? 'UPDATE app_meta SET meta_value = :v WHERE meta_key = :k'
            : 'INSERT INTO app_meta (meta_key, meta_value) VALUES (:k, :v)';
        $this->pdo->prepare($sql)->execute(['k' => $key, 'v' => $value]);
    }
}
