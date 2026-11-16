<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Mail;

use LiveScoreTracker\Infrastructure\Settings;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Sends emails through the hosting's SMTP server (spec § 20.1).
 */
final class SmtpMailer implements Mailer
{
    /** Open connection during a batch, reused from one email to the next. */
    private ?PHPMailer $batch = null;

    private bool $batchSent = false;

    /**
     * @param int $batchPauseMicroseconds pause between two emails of a batch
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly int $batchPauseMicroseconds = 500_000,
    ) {}

    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        if (self::isFictitious($to)) {
            throw new MailSkipped('Fictitious address (reserved example domain): nothing sent.');
        }
        if ($this->batch !== null) {
            if ($this->batchSent && $this->batchPauseMicroseconds > 0) {
                usleep($this->batchPauseMicroseconds);
            }
            $this->batchSent = true;
        }
        try {
            $this->message($to, $subject, $body, $replyTo)->send();
        } catch (PHPMailerException $exception) {
            throw new MailFailed($exception->getMessage(), 0, $exception);
        }
    }

    public function beginBatch(): void
    {
        $this->batch = new PHPMailer(true);
        $this->batch->SMTPKeepAlive = true;
        $this->batchSent = false;
    }

    public function endBatch(): void
    {
        $this->batch?->smtpClose();
        $this->batch = null;
    }

    /**
     * Addresses of the domains reserved for examples and tests (RFC 2606, RFC 6761),
     * such as the fictitious children's: they exist nowhere, and sending to them
     * gets the sending account flagged as a spammer.
     */
    public static function isFictitious(string $address): bool
    {
        $domain = self::domainOf($address);

        return preg_match('/(^|\.)(example\.(com|org|net)|example|test|invalid|localhost)$/', $domain) === 1;
    }

    /**
     * Sends an email and returns the dialogue with the SMTP server, to find out
     * why emails do not arrive. Credentials and the message itself are left out.
     *
     * @return array{sent: bool, error: string|null, transcript: list<string>}
     */
    public function diagnose(string $to, string $subject, string $body): array
    {
        if (self::isFictitious($to)) {
            return ['sent' => false, 'error' => 'Fictitious address (reserved example domain): nothing sent.', 'transcript' => []];
        }
        $transcript = [];
        $mail = $this->message($to, $subject, $body);
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = static function (string $line) use (&$transcript): void {
            $line = self::safeLine($line);
            if ($line !== null && \count($transcript) < 80) {
                $transcript[] = $line;
            }
        };
        try {
            $mail->send();

            return ['sent' => true, 'error' => null, 'transcript' => $transcript];
        } catch (PHPMailerException $exception) {
            return ['sent' => false, 'error' => $exception->getMessage(), 'transcript' => $transcript];
        }
    }

    /**
     * Keeps the server's answers and the client's commands that carry no secret
     * (never the login, the password or the content of the message).
     */
    public static function safeLine(string $line): ?string
    {
        $line = trim($line);
        if (str_starts_with($line, 'SERVER -> CLIENT:') || stripos($line, 'SMTP ERROR') === 0 || str_starts_with($line, 'SMTP connect()')) {
            return mb_substr($line, 0, 300);
        }
        if (preg_match('/^CLIENT -> SERVER: (EHLO|HELO|STARTTLS|MAIL FROM:|RCPT TO:|DATA$|QUIT)/i', $line) === 1) {
            return mb_substr($line, 0, 300);
        }
        if (preg_match('/^CLIENT -> SERVER: AUTH (\w+)/i', $line, $match) === 1) {
            return 'CLIENT -> SERVER: AUTH ' . $match[1];
        }

        return null;
    }

    /**
     * Domain of an address, for the Message-ID: the sender's domain rather than
     * the name of the hosting's server, which antispam filters find suspicious.
     */
    public static function domainOf(string $address): string
    {
        return strtolower(trim(substr((string) strrchr($address, '@'), 1), '. '));
    }

    private function message(string $to, string $subject, string $body, ?string $replyTo = null): PHPMailer
    {
        $mail = $this->batch ?? new PHPMailer(true);
        try {
            $mail->clearAllRecipients();
            $mail->clearReplyTos();
            $mail->clearCustomHeaders();
            $mail->isSMTP();
            $mail->Host = $this->settings->mailHost;
            $mail->Port = $this->settings->mailPort;
            $mail->SMTPAuth = $this->settings->mailUser !== '';
            $mail->Username = $this->settings->mailUser;
            $mail->Password = $this->settings->mailPassword;
            $mail->SMTPSecure = $this->settings->mailEncryption === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 15;
            // Envelope sender = From address (no bounce to the server's own account).
            $mail->setFrom($this->settings->mailFrom, $this->settings->mailFromName, true);
            $domain = self::domainOf($this->settings->mailFrom);
            $mail->MessageID = $domain !== '' ? \sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain) : '';
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            if ($replyTo !== null && $replyTo !== '' && strcasecmp($replyTo, $this->settings->mailFrom) !== 0) {
                $mail->addReplyTo($replyTo);
            }
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
        } catch (PHPMailerException $exception) {
            throw new MailFailed($exception->getMessage(), 0, $exception);
        }

        return $mail;
    }
}
