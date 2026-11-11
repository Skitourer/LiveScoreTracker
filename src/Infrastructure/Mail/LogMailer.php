<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

/**
 * Development mailer: appends emails to a file instead of sending them.
 */
final class LogMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function __construct(private readonly ?string $file) {}

    public function send(string $to, string $subject, string $body): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        if ($this->file === null) {
            return;
        }
        $directory = \dirname($this->file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0o770, true);
        }
        $entry = \sprintf("=== %s\nTo: %s\nSubject: %s\n\n%s\n\n", gmdate('Y-m-d H:i:s'), $to, $subject, $body);
        if (@file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailFailed('Cannot write the mail log.');
        }
    }
}
