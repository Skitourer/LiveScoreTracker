<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * Contact shown on help pages (spec § 12.6).
 */
final class Contact
{
    public function __construct(
        public readonly string $role,
        public readonly string $name,
        public readonly string $phone,
    ) {}
}
