<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Persistence;

use LiveScoreTracker\Http\RequestContext;

/**
 * Who made an entry: the account, the referee's first name on the shared
 * account, and the phone (spec § 19.1).
 */
final class Author
{
    public function __construct(
        public readonly ?int $userId,
        public readonly ?string $username,
        public readonly ?string $refereeName,
        public readonly ?string $deviceId,
    ) {}

    public static function from(RequestContext $context): self
    {
        return new self($context->user?->id, $context->user?->username, $context->refereeName(), $context->deviceId === '' ? null : $context->deviceId);
    }
}
