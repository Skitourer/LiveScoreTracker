<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Infrastructure\Clock;
use PDO;

/**
 * Ties ordered by hand by an admin (spec § 9.6). A decision is kept for a set
 * of children: it only applies while exactly these children are still tied.
 */
final class TieDecisionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {}

    /**
     * @param list<string> $orderedBibs best first
     */
    public function decide(string $categoryId, array $orderedBibs, string $by): void
    {
        $set = self::setKey($orderedBibs);
        $this->forget($categoryId, $orderedBibs);
        $this->pdo->prepare(
            'INSERT INTO tie_decisions (category_id, bib_set, bibs, decided_at, decided_by) VALUES (:cat, :set, :bibs, :at, :by)',
        )->execute([
            'cat' => $categoryId,
            'set' => $set,
            'bibs' => json_encode($orderedBibs, JSON_THROW_ON_ERROR),
            'at' => $this->clock->stamp(),
            'by' => $by,
        ]);
    }

    /**
     * @param list<string> $bibs
     */
    public function forget(string $categoryId, array $bibs): void
    {
        $this->pdo->prepare('DELETE FROM tie_decisions WHERE category_id = :cat AND bib_set = :set')
            ->execute(['cat' => $categoryId, 'set' => self::setKey($bibs)]);
    }

    /**
     * @return list<array{bibs: list<string>, decided_at: string, decided_by: string}>
     */
    public function forCategory(string $categoryId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tie_decisions WHERE category_id = :cat ORDER BY id');
        $statement->execute(['cat' => $categoryId]);
        $decisions = [];
        foreach (Row::all($statement) as $row) {
            $bibs = json_decode(Row::string($row, 'bibs'), true);
            if (!\is_array($bibs)) {
                continue;
            }
            $decisions[] = [
                'bibs' => array_values(array_map(static fn(mixed $bib): string => \is_scalar($bib) ? (string) $bib : '', $bibs)),
                'decided_at' => Row::string($row, 'decided_at'),
                'decided_by' => Row::string($row, 'decided_by'),
            ];
        }

        return $decisions;
    }

    /**
     * Same key whatever the order of the bibs.
     *
     * @param list<string> $bibs
     */
    public static function setKey(array $bibs): string
    {
        sort($bibs, SORT_NATURAL);

        return implode(',', $bibs);
    }
}
