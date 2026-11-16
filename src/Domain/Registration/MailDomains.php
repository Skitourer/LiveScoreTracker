<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * Tells whether an email domain can receive emails, to catch typos such as
 * "gmial.com": sending to non-existent addresses harms the sender's reputation.
 */
interface MailDomains
{
    /**
     * False only when the domain surely receives no email; true when unsure
     * (for example if the DNS cannot be queried).
     */
    public function receivesMail(string $domain): bool;
}
