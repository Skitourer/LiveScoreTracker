<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

use LiveScoreTracker\Domain\Registration\MailDomains;

/**
 * Looks the domain up in the DNS: an MX record, or failing that an address
 * record (RFC 5321 § 5.1). A domain with neither is refused only if the DNS
 * answers for a well-known domain, so a server without DNS refuses nothing.
 */
final class DnsMailDomains implements MailDomains
{
    /** @var array<string, bool> */
    private array $cache = [];

    private ?bool $dnsWorks = null;

    public function receivesMail(string $domain): bool
    {
        $domain = strtolower(trim($domain, '. '));
        if ($domain === '' || SmtpMailer::isFictitious('x@' . $domain)) {
            return true;
        }

        return $this->cache[$domain] ??= $this->hasRecords($domain) || !$this->dnsWorks();
    }

    private function hasRecords(string $domain): bool
    {
        foreach ([DNS_MX, DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($domain . '.', $type);
            if ($records === false) {
                return true; // Lookup failed: unsure.
            }
            if ($type === DNS_MX) {
                // "Null MX" (RFC 7505): the domain declares it accepts no email.
                foreach ($records as $record) {
                    if (($record['target'] ?? '') === '' || ($record['target'] ?? '') === '.') {
                        return false;
                    }
                }
            }
            if ($records !== []) {
                return true;
            }
        }

        return false;
    }

    private function dnsWorks(): bool
    {
        return $this->dnsWorks ??= (@dns_get_record('gmail.com.', DNS_MX) ?: []) !== [];
    }
}
