<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * A physical station of the competition, with one variant per level group (spec § 7.1).
 */
final class Workshop
{
    /**
     * @param list<Variant> $variants
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $help,
        public readonly array $variants,
    ) {}
}
