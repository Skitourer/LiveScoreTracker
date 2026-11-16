<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Unit\Mail;

use LiveScoreTracker\Infrastructure\Mail\LinkBase;
use PHPUnit\Framework\TestCase;

/**
 * Links in emails are always in https, except on a local development host.
 */
final class LinkBaseTest extends TestCase
{
    public function testLinksAreForcedToHttps(): void
    {
        self::assertSame('https://lst.example.org', LinkBase::of('', 'http://lst.example.org'));
        self::assertSame('https://lst.example.org', LinkBase::of('http://lst.example.org/', 'http://other.example.org'));
        self::assertSame('https://lst.example.org:8443', LinkBase::of('https://lst.example.org:8443', ''));
        self::assertSame('https://lst.example.org', LinkBase::of('', 'https://lst.example.org'));
    }

    public function testLocalHostsKeepHttp(): void
    {
        self::assertSame('http://localhost:8080', LinkBase::of('', 'http://localhost:8080'));
        self::assertSame('http://127.0.0.1:8000', LinkBase::of('', 'http://127.0.0.1:8000'));
        self::assertSame('http://lst.test', LinkBase::of('http://lst.test', ''));
    }
}
