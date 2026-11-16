<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Support;

use LiveScoreTracker\Domain\Registration\MailDomains;

/**
 * Mail domains without DNS: every domain receives emails except those listed.
 */
final class FakeMailDomains implements MailDomains
{
    /**
     * @param list<string> $dead
     */
    public function __construct(public array $dead = ['gmial.com']) {}

    public function receivesMail(string $domain): bool
    {
        return !\in_array(strtolower($domain), $this->dead, true);
    }
}
