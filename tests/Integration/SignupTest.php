<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of step 11: public registration form in several languages,
 * verification email, waiting list, expiry and withdrawal by the family.
 */
final class SignupTest extends TestCase
{
    private const CHILD = [
        'last_name' => 'Fictif', 'first_name' => 'Zoé', 'birth_date' => '2018-04-12', 'gender' => 'girl',
        'school' => 'École des Tilleuls', 'level' => 'CE2', 'guardian_name' => 'Anne Fictif',
        'guardian_phone' => '06 12 34 56 78', 'guardian_email' => 'anne@example.org',
        'responsibility' => '1', 'rules' => '1', 'website' => '',
    ];

    private string $dataDir;
    private WebClient $admin;
    private WebClient $family;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-signup-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->family = $this->admin->otherBrowser();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testAFamilyRegistersInGermanConfirmsAndWithdraws(): void
    {
        $this->family->get('/inscription?lang=de');
        self::assertStringContainsString('Die Anmeldung öffnet am', $this->family->lastBody, 'Closed before the configured dates');
        $this->forceOpen();

        $this->family->get('/inscription?lang=de');
        self::assertStringContainsString('<html lang="de" dir="ltr"', $this->family->lastBody);
        self::assertStringContainsString('<summary>Teilnahmebedingungen lesen</summary>', $this->family->lastBody, 'Rules shown inside the form');
        self::assertStringContainsString('Artikel 1', $this->family->lastBody);
        self::assertStringContainsString('>CE2</option>', $this->family->lastBody, 'Grade names are not translated');
        self::assertStringContainsString('Erziehungsberechtigte Person des Kindes', $this->family->lastBody);
        $this->family->get('/reglement');
        self::assertStringContainsString('Artikel 1', $this->family->lastBody, 'Rules page follows the chosen language');
        $this->family->get('/inscription');
        self::assertStringContainsString('Ein Kind anmelden', $this->family->lastBody);
        self::assertStringContainsString('lang="zh" hreflang="zh">中文</a>', $this->family->lastBody);

        // Sent at once: refused (robots fill forms instantly).
        $this->family->post('/inscription', self::CHILD);
        self::assertStringContainsString('zu schnell', $this->family->lastBody);

        $this->family->clock->advance(10);
        $sent = $this->family->post('/inscription', self::CHILD);
        self::assertSame('/inscription/envoye', $sent->getHeaderLine('Location'));
        $this->family->get('/inscription/envoye');
        self::assertStringContainsString('anne@example.org', $this->family->lastBody);

        $mail = $this->lastMail();
        self::assertSame('anne@example.org', $mail['to']);
        self::assertStringContainsString('Bitte bestätigen Sie', $mail['subject']);
        self::assertStringContainsString('Version française', $mail['body']);
        self::assertStringContainsString('Pour la confirmer, cliquez sur ce lien', $mail['body']);
        $verify = self::link($mail['body'], 'confirmer');

        // Pending until the family clicks the link.
        $this->admin->get('/registrations');
        self::assertMatchesRegularExpression('#<strong>1</strong> en attente de vérification#', $this->admin->lastBody);

        $this->family->get($verify);
        self::assertStringContainsString('Anmeldung bestätigt', $this->family->lastBody);
        self::assertStringContainsString('Startnummer: 1', $this->family->lastBody);
        $confirmation = $this->lastMail();
        self::assertStringContainsString('Anmeldung von Zoé bestätigt', $confirmation['subject']);
        self::assertStringContainsString('Numéro de dossard : 1', $confirmation['body']);
        $withdraw = self::link($confirmation['body'], 'desistement');

        $this->family->get($verify);
        self::assertStringContainsString('Bereits bestätigt', $this->family->lastBody);

        // Withdrawal through the email link, after a confirmation step.
        $this->family->get($withdraw);
        self::assertStringContainsString('Zoé FICTIF', $this->family->lastBody);
        $count = \count($this->admin->mailer->sent);
        $this->family->post($withdraw);
        self::assertStringContainsString('ist storniert', $this->family->lastBody);
        $mails = \array_slice($this->admin->mailer->sent, $count);
        self::assertCount(2, $mails, 'Family and organization');
        self::assertSame('contact@example.org', $mails[1]['to']);
        self::assertStringContainsString('Désistement : Zoé FICTIF', $mails[1]['subject']);
        $this->admin->get('/registrations/1');
        self::assertStringContainsString('désisté', $this->admin->lastBody);

        $this->family->get($withdraw);
        self::assertStringContainsString('ungültig', $this->family->lastBody, 'A link works once');
    }

    public function testWhenTheQuotaIsReachedTheChildGoesOnTheWaitingList(): void
    {
        $toml = (string) file_get_contents(\dirname(__DIR__, 2) . '/config.example/competition.toml');
        file_put_contents($this->dataDir . '/competition.toml', str_replace('quota = 100', 'quota = 1', $toml));
        $this->admin->get('/registrations/new');
        $this->admin->post('/registrations/new', [...self::CHILD, 'first_name' => 'Léo', 'gender' => 'boy']);
        $this->forceOpen();

        $this->family->get('/inscription');
        self::assertStringContainsString('liste d&#039;attente', $this->family->lastBody);
        $this->family->clock->advance(10);
        $this->family->post('/inscription', self::CHILD);
        $this->family->get(self::link($this->lastMail()['body'], 'confirmer'));
        self::assertStringContainsString('Liste d&#039;attente', $this->family->lastBody);

        $mails = \array_slice($this->admin->mailer->sent, -2);
        self::assertStringContainsString('liste d\'attente', $mails[0]['subject']);
        self::assertSame('contact@example.org', $mails[1]['to']);
        self::assertStringContainsString('Enfants en liste d\'attente : 1', $mails[1]['body']);
    }

    public function testAnUnverifiedRegistrationExpiresAfter48Hours(): void
    {
        $this->forceOpen();
        $this->family->get('/inscription?lang=ar');
        self::assertStringContainsString('<html lang="ar" dir="rtl"', $this->family->lastBody);
        $this->family->clock->advance(10);
        $this->family->post('/inscription', self::CHILD);
        $verify = self::link($this->lastMail()['body'], 'confirmer');

        $this->family->clock->advance(49 * 3600);
        $this->family->get($verify);
        self::assertStringContainsString('انتهت صلاحية الرابط', $this->family->lastBody);

        // The same child may then be registered again.
        $this->family->get('/inscription');
        $this->family->clock->advance(10);
        self::assertSame('/inscription/envoye', $this->family->post('/inscription', self::CHILD)->getHeaderLine('Location'));
    }

    public function testRobotsAndDuplicatesAreTurnedAway(): void
    {
        $this->forceOpen();
        $this->family->get('/inscription');
        $this->family->clock->advance(10);
        $count = \count($this->admin->mailer->sent);
        $this->family->post('/inscription', [...self::CHILD, 'website' => 'https://spam.example']);
        self::assertCount($count, $this->admin->mailer->sent, 'Trap field filled: nothing stored, no email');

        $this->family->get('/inscription');
        $this->family->clock->advance(10);
        // A grade typed by mistake gives a warning; once corrected, no box to tick.
        $this->family->post('/inscription', [...self::CHILD, 'level' => 'CM2']);
        self::assertStringContainsString('name="confirm_warnings" value="1">', $this->family->lastBody);
        $this->family->clock->advance(10);
        self::assertSame('/inscription/envoye', $this->family->post('/inscription', self::CHILD)->getHeaderLine('Location'));

        $this->family->get('/inscription?another=1');
        $this->family->clock->advance(10);
        $this->family->post('/inscription', [...self::CHILD, 'first_name' => 'Léa', 'responsibility' => '']);
        self::assertStringContainsString('responsabilité de l&#039;adulte', $this->family->lastBody);

        $this->family->get('/inscription?another=1');
        self::assertStringContainsString('value="anne@example.org"', $this->family->lastBody, 'Adult kept for brothers and sisters');
        $this->family->clock->advance(10);
        $this->family->post('/inscription', self::CHILD);
        self::assertStringContainsString('déjà inscrit', $this->family->lastBody);
    }

    private function forceOpen(): void
    {
        $this->admin->get('/registrations');
        $this->admin->post('/registrations/window', ['state' => 'open']);
        $this->admin->get('/registrations');
        self::assertMatchesRegularExpression('#Inscriptions en ligne : (ouvertes|complètes)#', $this->admin->lastBody);
        self::assertStringContainsString('href="/registrations">⟳ Actualiser</a>', $this->admin->lastBody);
    }

    /**
     * @return array{to: string, subject: string, body: string}
     */
    private function lastMail(): array
    {
        $last = end($this->admin->mailer->sent);
        self::assertIsArray($last);

        return $last;
    }

    private static function link(string $body, string $kind): string
    {
        self::assertMatchesRegularExpression('#https://lst\.example\.test(/inscription/' . $kind . '/[a-f0-9]{48})#', $body);
        preg_match('#https://lst\.example\.test(/inscription/' . $kind . '/[a-f0-9]{48})#', $body, $match);

        return $match[1];
    }
}
