<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Mail;

use LiveScoreTracker\Infrastructure\Mail\SmtpMailer;
use LiveScoreTracker\Infrastructure\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Dialogue with the SMTP server shown on the "Test email" page.
 */
final class SmtpMailerTest extends TestCase
{
    public function testTranscriptKeepsNoSecretAndNoContent(): void
    {
        self::assertSame('SERVER -> CLIENT: 250 2.0.0 Ok: queued as 4ABC', SmtpMailer::safeLine("SERVER -> CLIENT: 250 2.0.0 Ok: queued as 4ABC\n"));
        self::assertSame('CLIENT -> SERVER: MAIL FROM:<noreply@example.org>', SmtpMailer::safeLine('CLIENT -> SERVER: MAIL FROM:<noreply@example.org>'));
        self::assertSame('CLIENT -> SERVER: AUTH LOGIN', SmtpMailer::safeLine('CLIENT -> SERVER: AUTH LOGIN'));
        self::assertSame('CLIENT -> SERVER: AUTH PLAIN', SmtpMailer::safeLine('CLIENT -> SERVER: AUTH PLAIN AG5vcmVwbHkAc2VjcmV0'));
        // Login, password (base64) and the lines of the message are dropped.
        self::assertNull(SmtpMailer::safeLine('CLIENT -> SERVER: bm9yZXBseUBleGFtcGxlLm9yZw=='));
        self::assertNull(SmtpMailer::safeLine('CLIENT -> SERVER: Subject: LiveScoreTracker'));
        self::assertNull(SmtpMailer::safeLine('CLIENT -> SERVER: Bonjour'));
    }

    public function testUnreachableServerIsReportedNotThrown(): void
    {
        $settings = Settings::fromEnvironment(\dirname(__DIR__, 3), [
            'MAIL_TRANSPORT' => 'smtp',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => '1',
            'MAIL_FROM' => 'noreply@example.org',
        ]);
        $result = (new SmtpMailer($settings))->diagnose('camille@example.org', 'Test', 'Test');
        self::assertFalse($result['sent']);
        self::assertNotNull($result['error']);
    }
}
