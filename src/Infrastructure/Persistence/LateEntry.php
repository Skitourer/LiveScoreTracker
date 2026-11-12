<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

/**
 * An entry received after the competition was closed (spec § 8.8).
 */
final class LateEntry
{
    /**
     * @param array<mixed> $payload the entry as sent by the phone
     */
    public function __construct(
        public readonly int $id,
        public readonly string $clientId,
        public readonly string $workshopId,
        public readonly int $bib,
        public readonly string $variantId,
        public readonly array $payload,
        public readonly string $receivedAt,
        public readonly Author $author,
        public readonly ?string $decision = null,
    ) {}

    /**
     * Value sent (storage units), or null for a foul.
     */
    public function measured(): ?int
    {
        $measured = $this->payload['measured'] ?? null;

        return \is_int($measured) ? $measured : null;
    }

    /**
     * When it was made on the phone (UTC 'Y-m-d H:i:s'), if the phone said so.
     */
    public function madeAt(): ?string
    {
        $at = $this->payload['at'] ?? null;

        return \is_int($at) ? gmdate('Y-m-d H:i:s', intdiv($at, 1000)) : null;
    }

    public function authorName(): string
    {
        return $this->author->refereeName ?? $this->author->username ?? '?';
    }
}
