<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

/**
 * Sends plain-text emails.
 */
interface Mailer
{
    /**
     * @throws MailFailed
     */
    public function send(string $to, string $subject, string $body): void;
}
