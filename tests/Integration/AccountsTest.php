<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Security\TwoFactor;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end tests of step 2: setup, login, 2FA, passwords, roles, referee account, audit log.
 */
final class AccountsTest extends TestCase
{
    private const ADMIN_PASSWORD = 'correct-horse-battery';

    private string $dataDir;
    private WebClient $admin;
    private string $totpSecret = '';

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-accounts-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testFirstAdminSetupIsProtectedAndOneTime(): void
    {
        self::assertSame(200, $this->admin->get('/setup')->getStatusCode());

        $wrong = $this->admin->post('/setup', $this->setupForm(['setup_token' => 'nope']));
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Jeton d&#039;installation incorrect', $this->admin->lastBody);

        $this->createFirstAdmin();
        self::assertSame(404, $this->admin->get('/setup')->getStatusCode());
    }

    public function testAdminMustSetUpTwoFactorThenUseIt(): void
    {
        $this->createFirstAdmin();

        $login = $this->admin->post('/login', ['username' => 'boss', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame('/account/2fa', $login->getHeaderLine('Location'));

        // Nothing else is reachable before 2FA is set up.
        self::assertSame('/account/2fa', $this->admin->get('/dashboard')->getHeaderLine('Location'));

        $setup = $this->admin->get('/account/2fa');
        self::assertStringContainsString('<svg', (string) $setup->getBody());
        $secret = $this->secretFromPage();

        self::assertSame(422, $this->admin->post('/account/2fa', ['code' => '000000'])->getStatusCode());
        $codesPage = $this->admin->post('/account/2fa', ['code' => self::totp($secret)]);
        preg_match_all('/<code>([a-z0-9]{4}-[a-z0-9]{4})<\/code>/', (string) $codesPage->getBody(), $m);
        self::assertCount(10, $m[1]);

        self::assertSame(200, $this->admin->get('/dashboard')->getStatusCode());
        self::assertStringContainsString('Comptes', $this->admin->lastBody);

        // Next login asks for the code; a recovery code works once.
        $this->logout($this->admin);
        $login = $this->admin->post('/login', ['username' => 'boss', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame('/login/2fa', $login->getHeaderLine('Location'));
        self::assertSame(401, $this->admin->post('/login/2fa', ['code' => '123456'])->getStatusCode());
        self::assertSame('/dashboard', $this->admin->post('/login/2fa', ['code' => $m[1][0]])->getHeaderLine('Location'));

        $this->logout($this->admin);
        $this->admin->post('/login', ['username' => 'boss', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(401, $this->admin->post('/login/2fa', ['code' => $m[1][0]])->getStatusCode(), 'A recovery code is single-use.');
    }

    public function testOrganizerWithTemporaryPasswordAndLimitedRights(): void
    {
        $this->loginAsAdmin();
        $created = $this->admin->post('/admin/users/new', [
            'role' => 'organizer',
            'username' => 'paul',
            'display_name' => 'Paul Organizer',
            'email' => 'paul@example.test',
            'password' => 'temporary-pass-1',
        ]);
        self::assertSame('/admin/users', $created->getHeaderLine('Location'));

        $paul = $this->admin->otherBrowser();
        $login = $paul->post('/login', ['username' => 'Paul', 'password' => 'temporary-pass-1']);
        self::assertSame('/dashboard', $login->getHeaderLine('Location'));
        self::assertSame('/account/password', $paul->get('/dashboard')->getHeaderLine('Location'));

        self::assertSame(422, $paul->post('/account/password', [
            'current_password' => 'temporary-pass-1', 'password' => 'short', 'password_confirm' => 'short',
        ])->getStatusCode());
        $changed = $paul->post('/account/password', [
            'current_password' => 'temporary-pass-1', 'password' => 'my-own-password-2', 'password_confirm' => 'my-own-password-2',
        ]);
        self::assertSame('/dashboard', $changed->getHeaderLine('Location'));

        self::assertSame(200, $paul->get('/dashboard')->getStatusCode());
        self::assertStringContainsString('Saisie des résultats', $paul->lastBody);
        self::assertStringNotContainsString('Journal d', $paul->lastBody);
        self::assertSame(403, $paul->get('/admin/users')->getStatusCode());
        self::assertSame(403, $paul->get('/admin/audit')->getStatusCode());
        self::assertSame(403, $paul->post('/admin/phase', ['phase' => 'running'])->getStatusCode());
    }

    public function testRefereeAccountFollowsThePhaseAndCanBeDisconnected(): void
    {
        $this->loginAsAdmin();
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);

        $phone = $this->admin->otherBrowser();
        $refused = $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        self::assertSame(403, $refused->getStatusCode(), 'Inactive during preparation.');

        $this->admin->post('/admin/phase', ['phase' => 'running']);
        self::assertSame('/dashboard', $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027'])->getHeaderLine('Location'));
        self::assertSame(200, $phone->get('/dashboard')->getStatusCode());
        self::assertStringContainsString('Saisie des résultats', $phone->lastBody);
        self::assertSame(403, $phone->get('/admin/audit')->getStatusCode());

        $this->admin->get('/admin/referee');
        self::assertStringContainsString('Téléphones connectés : 1', $this->admin->lastBody);
        $this->admin->post('/admin/referee', ['op' => 'disconnect']);
        self::assertStringStartsWith('/login', $phone->get('/dashboard')->getHeaderLine('Location'));

        // Back to preparation logs the phone out too.
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $this->admin->post('/admin/phase', ['phase' => 'preparation']);
        self::assertStringStartsWith('/login', $phone->get('/dashboard')->getHeaderLine('Location'));
    }

    public function testFailedLoginsAreThrottled(): void
    {
        $this->createFirstAdmin();
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->admin->post('/login', ['username' => 'boss', 'password' => 'wrong'])->getStatusCode());
        }
        $blocked = $this->admin->post('/login', ['username' => 'boss', 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertStringContainsString('Trop de tentatives', $this->admin->lastBody);
    }

    public function testRefereeThrottlingIsPerPhone(): void
    {
        $this->loginAsAdmin();
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $this->admin->post('/admin/phase', ['phase' => 'running']);

        $clumsy = $this->admin->otherBrowser();
        $clumsy->ip = '198.51.100.1';
        for ($i = 0; $i < 6; ++$i) {
            $clumsy->post('/login', ['username' => 'arbitre', 'password' => 'bad-guess']);
        }
        self::assertSame(429, $clumsy->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027'])->getStatusCode());

        $other = $this->admin->otherBrowser();
        $other->ip = '198.51.100.1'; // same mobile network
        self::assertSame('/dashboard', $other->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027'])->getHeaderLine('Location'));
    }

    public function testForgotPasswordByEmailLink(): void
    {
        $this->loginAsAdmin();
        $this->admin->post('/admin/users/new', [
            'role' => 'organizer', 'username' => 'lea', 'display_name' => 'Léa', 'email' => 'lea@example.test', 'password' => 'temporary-pass-1',
        ]);

        $lea = $this->admin->otherBrowser();
        $lea->post('/password/forgot', ['email' => 'nobody@example.test']);
        self::assertStringContainsString('Si un compte correspond', $lea->lastBody);
        self::assertCount(0, $this->admin->mailer->sent);

        $lea->post('/password/forgot', ['email' => 'LEA@example.test']);
        self::assertStringContainsString('Si un compte correspond', $lea->lastBody);
        self::assertCount(1, $this->admin->mailer->sent);
        self::assertSame('lea@example.test', $this->admin->mailer->sent[0]['to']);
        $link = self::firstMatch('#https://lst\.example\.test(/password/reset/[a-f0-9]{64})#', $this->admin->mailer->sent[0]['body']);

        self::assertSame(200, $lea->get($link)->getStatusCode());
        $reset = $lea->post($link, ['password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
        self::assertSame('/login', $reset->getHeaderLine('Location'));
        self::assertSame(410, $lea->get($link)->getStatusCode(), 'The link is single-use.');

        self::assertSame('/dashboard', $lea->post('/login', ['username' => 'lea', 'password' => 'brand-new-password'])->getHeaderLine('Location'));
        self::assertSame(200, $lea->get('/dashboard')->getStatusCode(), 'A reset password is not temporary.');
    }

    public function testAdminManagement(): void
    {
        $this->loginAsAdmin();
        $this->admin->get('/admin/users');
        $myId = self::firstMatch('#/admin/users/(\d+)#', $this->admin->lastBody);

        // The last admin cannot be disabled (and nobody can disable themselves).
        $this->admin->post('/admin/users/' . $myId, ['op' => 'disable']);
        self::assertStringContainsString('désactiver votre propre compte', $this->admin->lastBody);

        $this->admin->post('/admin/users/new', [
            'role' => 'admin', 'username' => 'second', 'display_name' => 'Second Admin', 'email' => 'second@example.test', 'password' => 'temporary-pass-2',
        ]);
        $this->admin->get('/admin/users');
        self::assertStringContainsString('Second Admin', $this->admin->lastBody);
        preg_match_all('#/admin/users/(\d+)#', $this->admin->lastBody, $all);
        $secondId = end($all[1]);

        $this->admin->post('/admin/users/' . $secondId, ['op' => 'disable']);
        $second = $this->admin->otherBrowser();
        self::assertSame(401, $second->post('/login', ['username' => 'second', 'password' => 'temporary-pass-2'])->getStatusCode());

        $this->admin->get('/admin/audit');
        foreach (['Premier admin créé', 'Compte créé', 'Compte désactivé', 'Double authentification activée'] as $entry) {
            self::assertStringContainsString($entry, $this->admin->lastBody);
        }
    }

    public function testFormsRequireTheCsrfToken(): void
    {
        $response = $this->admin->post('/login', ['username' => 'x', 'password' => 'y'], withCsrf: false);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Page expirée', $this->admin->lastBody);
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->admin->get('/login');

        self::assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $cookies = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('lst_session=', $cookies);
        self::assertStringContainsString('HttpOnly', $cookies);
        self::assertStringContainsString('Secure', $cookies);
        self::assertStringContainsString('SameSite=Lax', $cookies);
    }

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        self::assertSame('/login?next=%2Fadmin%2Fusers', $this->admin->get('/admin/users')->getHeaderLine('Location'));
        self::assertSame('/login?next=%2Fdashboard', $this->admin->get('/dashboard')->getHeaderLine('Location'));
    }

    // ------------------------------------------------------------------ helpers

    private function createFirstAdmin(): void
    {
        $response = $this->admin->post('/setup', $this->setupForm());
        self::assertSame('/login', $response->getHeaderLine('Location'), $this->admin->lastBody);
    }

    private function loginAsAdmin(): void
    {
        $this->createFirstAdmin();
        $this->admin->post('/login', ['username' => 'boss', 'password' => self::ADMIN_PASSWORD]);
        $this->admin->get('/account/2fa');
        $this->totpSecret = $this->secretFromPage();
        $this->admin->post('/account/2fa', ['code' => self::totp($this->totpSecret)]);
        self::assertSame(200, $this->admin->get('/dashboard')->getStatusCode());
    }

    private function logout(WebClient $client): ResponseInterface
    {
        return $client->post('/logout');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function setupForm(array $overrides = []): array
    {
        return [
            'setup_token' => 'test-deploy-token',
            'username' => 'boss',
            'display_name' => 'Big Boss',
            'email' => 'boss@example.test',
            'password' => self::ADMIN_PASSWORD,
            'password_confirm' => self::ADMIN_PASSWORD,
            ...$overrides,
        ];
    }

    /**
     * @return non-empty-string
     */
    private function secretFromPage(): string
    {
        $secret = str_replace(' ', '', self::firstMatch('#<code class="secret">([A-Z2-7 ]+)</code>#', $this->admin->lastBody));
        if ($secret === '') {
            self::fail('Empty 2FA secret.');
        }

        return $secret;
    }

    /**
     * First capture group of the pattern, failing the test when there is no match.
     */
    private static function firstMatch(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[1])) {
            self::fail("Pattern {$pattern} not found.");
        }

        return $m[1];
    }

    /**
     * @param non-empty-string $secret
     */
    private static function totp(string $secret): string
    {
        return (new TwoFactor(new Clock()))->currentCode($secret);
    }
}
