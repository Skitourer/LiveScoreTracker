<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

/**
 * Sends plain-text emails.
 */
interface Mailer
{
    /**
     * @param string|null $replyTo address answers go to (the edition's contact), if any
     *
     * @throws MailFailed
     */
    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void;

    /**
     * Starts a series of sends (import): one connection, a pause between two emails,
     * so the hosting's antispam filter does not take it for a mass mailing.
     */
    public function beginBatch(): void;

    public function endBatch(): void;
}
