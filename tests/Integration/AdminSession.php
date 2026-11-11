<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Security\TwoFactor;
use PHPUnit\Framework\Assert;

/**
 * Creates the first admin through /setup and logs in with two-factor authentication.
 */
final class AdminSession
{
    public const PASSWORD = 'correct-horse-battery';

    public static function start(WebClient $client): void
    {
        $setup = $client->post('/setup', [
            'setup_token' => 'test-deploy-token',
            'username' => 'boss',
            'display_name' => 'Big Boss',
            'email' => 'boss@example.test',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
        ]);
        Assert::assertSame('/login', $setup->getHeaderLine('Location'));
        $client->post('/login', ['username' => 'boss', 'password' => self::PASSWORD]);
        $client->get('/account/2fa');
        if (preg_match('#<code class="secret">([A-Z2-7 ]+)</code>#', $client->lastBody, $m) !== 1) {
            Assert::fail('2FA secret not found on the setup page.');
        }
        $secret = str_replace(' ', '', $m[1]);
        if ($secret === '') {
            Assert::fail('Empty 2FA secret.');
        }
        $client->post('/account/2fa', ['code' => (new TwoFactor(new Clock()))->currentCode($secret)]);
        Assert::assertSame(200, $client->get('/dashboard')->getStatusCode());
    }

    /**
     * Creates an organizer and returns a logged-in browser for them.
     */
    public static function organizer(WebClient $admin): WebClient
    {
        $admin->post('/admin/users/new', [
            'role' => 'organizer', 'username' => 'orga', 'display_name' => 'Orga', 'email' => 'orga@example.test', 'password' => 'temporary-pass-1',
        ]);
        $organizer = $admin->otherBrowser();
        $organizer->post('/login', ['username' => 'orga', 'password' => 'temporary-pass-1']);
        $organizer->post('/account/password', [
            'current_password' => 'temporary-pass-1', 'password' => 'organizer-pass-2', 'password_confirm' => 'organizer-pass-2',
        ]);
        Assert::assertSame(200, $organizer->get('/dashboard')->getStatusCode());

        return $organizer;
    }
}
