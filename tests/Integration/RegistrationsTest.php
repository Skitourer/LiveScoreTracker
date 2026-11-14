<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Demo\ImportSample;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of step 3: registrations screens, bibs, history, import and export.
 */
final class RegistrationsTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-registrations-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testDuringPreparationAWithdrawnChildGivesTheBibBack(): void
    {
        $first = $this->add(['last_name' => 'martin', 'first_name' => 'zoé']);
        self::assertSame('/registrations/1', $first->getHeaderLine('Location'));
        $this->admin->get('/registrations/1');
        self::assertStringContainsString('<span class="bib">1</span> Zoé MARTIN', $this->admin->lastBody);

        $this->add(['last_name' => 'Durand', 'first_name' => 'Hugo', 'gender' => 'boy']);
        $this->add(['last_name' => 'Petit', 'first_name' => 'Léo', 'gender' => 'boy']);
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('Son dossard 2 sera libéré', $this->admin->lastBody);

        // Withdraw bib 2: the next child gets it (paper bibs are limited).
        $this->admin->post('/registrations/2', ['op' => 'status', 'status' => 'withdrawn']);
        $this->admin->get('/registrations/2');
        self::assertStringNotContainsString('<span class="bib">', $this->admin->lastBody);
        self::assertStringContainsString('Dossard libéré', $this->admin->lastBody);
        $this->add(['last_name' => 'Roux', 'first_name' => 'Lina']);
        $this->admin->get('/registrations/4');
        self::assertStringContainsString('<span class="bib">2</span>', $this->admin->lastBody);

        $this->admin->get('/registrations');
        self::assertStringContainsString('<strong>3</strong> / 100', $this->admin->lastBody);
        self::assertStringNotContainsString('DURAND', $this->admin->lastBody, 'Withdrawn children are hidden by default');
        $this->admin->get('/registrations?status=all');
        self::assertStringContainsString('DURAND', $this->admin->lastBody);

        // Registered again: Hugo gets the first free bib, and a new confirmation email.
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('Réinscrire', $this->admin->lastBody);
        $sent = \count($this->admin->mailer->sent);
        $this->admin->post('/registrations/2', ['op' => 'status', 'status' => 'registered', 'send_email' => '1']);
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('<span class="bib">4</span> Hugo DURAND', $this->admin->lastBody);
        self::assertCount($sent + 1, $this->admin->mailer->sent);
    }

    public function testDuringTheCompetitionAWithdrawnChildKeepsTheBib(): void
    {
        $this->add(['last_name' => 'Martin', 'first_name' => 'Zoé']);
        $this->add(['last_name' => 'Durand', 'first_name' => 'Hugo', 'gender' => 'boy']);
        $this->admin->post('/admin/phase', ['phase' => 'running']);

        $this->admin->get('/registrations/2');
        self::assertStringContainsString('il garde son dossard 2', $this->admin->lastBody);
        $this->admin->post('/registrations/2', ['op' => 'status', 'status' => 'withdrawn']);
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('<span class="bib">2</span>', $this->admin->lastBody);

        // An on-site addition never gets a bib held by a withdrawn child.
        $this->add(['last_name' => 'Petit', 'first_name' => 'Léo', 'gender' => 'boy']);
        $this->admin->get('/registrations/3');
        self::assertStringContainsString('<span class="bib">3</span>', $this->admin->lastBody);

        // Registered again: Hugo finds his bib.
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('Il retrouve son dossard 2', $this->admin->lastBody);
        $this->admin->post('/registrations/2', ['op' => 'status', 'status' => 'registered']);
        $this->admin->get('/registrations/2');
        self::assertStringContainsString('<span class="bib">2</span> Hugo DURAND', $this->admin->lastBody);
    }

    public function testDuplicatesAreRefusedAndWarningsMustBeConfirmed(): void
    {
        $this->add();
        $duplicate = $this->add(['first_name' => 'ZOE ']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('déjà inscrit : dossard 1', $this->admin->lastBody);

        // Born in 2019 but in CM1: unusual, must be confirmed.
        $unusual = $this->add(['last_name' => 'Bernard', 'birth_date' => '2019-02-01']);
        self::assertSame(422, $unusual->getStatusCode());
        self::assertStringContainsString('inhabituelle', $this->admin->lastBody);
        $confirmed = $this->add(['last_name' => 'Bernard', 'birth_date' => '2019-02-01', 'confirm_warnings' => '1']);
        self::assertSame('/registrations/2', $confirmed->getHeaderLine('Location'));
    }

    public function testConfirmationEmailRemindsVolunteers(): void
    {
        $this->add(['send_email' => '1']);
        self::assertCount(1, $this->admin->mailer->sent);
        $mail = $this->admin->mailer->sent[0];
        self::assertSame('anne@example.org', $mail['to']);
        self::assertSame('Olympiades fictives 2027 : inscription de Zoé confirmée', $mail['subject']);
        self::assertStringContainsString('Bonjour Anne Martin', $mail['body']);
        self::assertStringContainsString('Numéro de dossard : 1.', $mail['body']);
        self::assertStringContainsString('dimanche 6 juin 2027', $mail['body']);
        self::assertStringContainsString('volontaire', $mail['body']);
        self::assertStringContainsString('Email de confirmation envoyé à anne@example.org', $this->admin->follow($this->lastResponse())->getBody()->__toString());

        // Not a volunteer: no volunteer paragraph. No checkbox: no email.
        $this->add(['last_name' => 'Durand', 'first_name' => 'Hugo', 'gender' => 'boy', 'volunteer' => '', 'send_email' => '1']);
        self::assertStringNotContainsString('volontaire', $this->admin->mailer->sent[1]['body']);
        $this->add(['last_name' => 'Petit', 'first_name' => 'Léo', 'gender' => 'boy']);
        self::assertCount(2, $this->admin->mailer->sent);

        // Sending it again from the child card.
        $this->admin->post('/registrations/3', ['op' => 'resend_email']);
        self::assertCount(3, $this->admin->mailer->sent);
        self::assertSame('anne@example.org', $this->admin->mailer->sent[2]['to']);
    }

    public function testEmailsOnlyLoggedAreNotAnnouncedAsSent(): void
    {
        // MAIL_TRANSPORT=log: the email is written to a file, the screen must not say "sent".
        $this->admin->mailTransport = 'log';
        $this->add(['send_email' => '1']);
        $page = $this->admin->follow($this->lastResponse())->getBody()->__toString();
        self::assertStringNotContainsString('Email de confirmation envoyé', $page);
        self::assertStringContainsString('Email pour anne@example.org non envoyé', $page);

        self::assertStringContainsString('Emails : non envoyés', $this->admin->get('/status')->getBody()->__toString());
        $this->admin->mailTransport = 'smtp';
        self::assertStringContainsString('Emails : envoyés par SMTP', $this->admin->get('/status')->getBody()->__toString());
    }

    public function testAdminCanSendATestEmail(): void
    {
        self::assertStringContainsString('/admin/mail-test', $this->admin->get('/status')->getBody()->__toString());
        self::assertStringContainsString('Email de test', $this->admin->get('/admin/mail-test')->getBody()->__toString());

        $page = $this->admin->post('/admin/mail-test', ['to' => 'camille@ecole-des-tilleuls-fictive.fr'])->getBody()->__toString();
        self::assertStringContainsString('accepté le message pour camille@ecole-des-tilleuls-fictive.fr', $page);
        self::assertSame('camille@ecole-des-tilleuls-fictive.fr', $this->admin->mailer->sent[0]['to']);
        self::assertSame('LiveScoreTracker : email de test', $this->admin->mailer->sent[0]['subject']);

        // Fictitious addresses (test data) are never sent to.
        self::assertStringContainsString('adresse fictive', $this->admin->post('/admin/mail-test', ['to' => 'parent1@example.org'])->getBody()->__toString());
        self::assertStringContainsString('Adresse email invalide', $this->admin->post('/admin/mail-test', ['to' => 'camille'])->getBody()->__toString());
        self::assertCount(1, $this->admin->mailer->sent);
    }

    public function testCorrectingACardExplainsWhatIsWrong(): void
    {
        $this->add();
        // The browser no longer blocks the form silently: the server answers with the messages.
        $card = $this->admin->get('/registrations/1')->getBody()->__toString();
        self::assertStringContainsString('<form method="post" class="form wide-form" novalidate>', $card);

        $response = $this->admin->post('/registrations/1', [...self::form(['school' => '', 'guardian_phone' => '12']), 'op' => 'update']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<ul class="form-errors" role="alert" tabindex="-1" autofocus>', $this->admin->lastBody);
        self::assertStringContainsString('école est obligatoire', $this->admin->lastBody);
        self::assertStringContainsString('Numéro de téléphone invalide', $this->admin->lastBody);
        self::assertStringContainsString('<details class="card" open>', $this->admin->lastBody);
        self::assertStringContainsString('value="12"', $this->admin->lastBody);
    }

    public function testPhoneNumbersMustBeComplete(): void
    {
        self::assertSame(422, $this->add(['guardian_phone' => '06 12 34 56'])->getStatusCode());
        self::assertStringContainsString('Numéro de téléphone invalide', $this->admin->lastBody);
        self::assertSame(422, $this->add(['guardian_email' => 'anne@example'])->getStatusCode());
        self::assertStringContainsString('Adresse email invalide', $this->admin->lastBody);
        self::assertSame('/registrations/1', $this->add(['guardian_phone' => '+33 (0)6 11 22 33 44'])->getHeaderLine('Location'));
    }

    public function testCorrectionIsRecordedInTheHistory(): void
    {
        $this->add();
        $this->admin->post('/registrations/1', ['op' => 'update', ...self::form(['school' => 'École des Marronniers', 'volunteer' => ''])]);
        $this->admin->get('/registrations/1');
        self::assertStringContainsString('Fiche corrigée', $this->admin->lastBody);
        self::assertStringContainsString('École des Tilleuls → École des Marronniers', $this->admin->lastBody);
        self::assertStringContainsString('Volontaire : oui → non', $this->admin->lastBody);
    }

    public function testOnSiteAdditionDuringTheCompetition(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->get('/registrations/new');
        self::assertStringContainsString('ajout sur place', $this->admin->lastBody);
        $response = $this->add(['birth_date' => '']);
        self::assertSame('/registrations/1', $response->getHeaderLine('Location'));
        $this->admin->get('/registrations');
        self::assertStringContainsString('sur place', $this->admin->lastBody);
    }

    public function testOrganizerRights(): void
    {
        $organizer = AdminSession::organizer($this->admin);
        $this->add();

        self::assertSame(200, $organizer->get('/registrations')->getStatusCode());
        self::assertSame(200, $organizer->get('/registrations/1')->getStatusCode());
        self::assertStringNotContainsString('Désinscrire', $organizer->lastBody);
        self::assertSame(303, $organizer->post('/registrations/1', ['op' => 'update', ...self::form(['first_name' => 'Zoe'])])->getStatusCode());
        self::assertSame(403, $organizer->post('/registrations/1', ['op' => 'status', 'status' => 'withdrawn'])->getStatusCode());
        self::assertSame(403, $organizer->get('/registrations/import')->getStatusCode());
        self::assertSame('/registrations/2', $organizer->post('/registrations/new', self::form(['last_name' => 'Other']))->getHeaderLine('Location'));
    }

    public function testImportPreviewThenConfirmation(): void
    {
        $this->add(['last_name' => 'Already', 'first_name' => 'There']);
        $file = $this->sampleFile();

        $preview = $this->admin->upload('/registrations/import', 'file', $file, 'registrations.xlsx');
        self::assertSame(200, $preview->getStatusCode(), $this->admin->lastBody);
        self::assertStringContainsString('<strong>40</strong> nouveau', $this->admin->lastBody);
        self::assertStringContainsString('<strong>1</strong> erreur', $this->admin->lastBody);
        self::assertStringContainsString('Classe non reconnue : « 6e »', $this->admin->lastBody);
        self::assertStringContainsString('<strong>1</strong> doublon', $this->admin->lastBody);

        $done = $this->admin->post('/registrations/import', ['op' => 'confirm', 'send_emails' => '1']);
        self::assertCount(40, $this->admin->mailer->sent);
        self::assertSame('/registrations', $done->getHeaderLine('Location'));
        $this->admin->get('/registrations');
        self::assertStringContainsString('<strong>41</strong> / 100', $this->admin->lastBody);
        self::assertStringContainsString('40 enfant(s) ajouté(s)', $this->admin->lastBody);

        // Importing the same file again adds nobody: bibs do not move.
        $this->admin->upload('/registrations/import', 'file', $file, 'registrations.xlsx');
        self::assertStringContainsString('<strong>0</strong> nouveau', $this->admin->lastBody);
        self::assertStringContainsString('<strong>41</strong> déjà inscrit', $this->admin->lastBody, 'The duplicate line is now known too');
    }

    public function testImportRefusesUnknownFormatsAndMissingColumns(): void
    {
        $csv = $this->dataDir . '/wrong.csv';
        file_put_contents($csv, "Name;Class\nZoé;CM1\n");
        $this->admin->upload('/registrations/import', 'file', $csv, 'wrong.csv');
        self::assertStringContainsString('Colonnes obligatoires introuvables', $this->admin->lastBody);

        $txt = $this->dataDir . '/notes.txt';
        file_put_contents($txt, 'hello');
        self::assertSame(422, $this->admin->upload('/registrations/import', 'file', $txt, 'notes.txt')->getStatusCode());
        self::assertStringContainsString('Format non pris en charge', $this->admin->lastBody);
    }

    public function testCsvImportWithFrenchSeparatorAndLatin1(): void
    {
        $csv = $this->dataDir . '/inscriptions.csv';
        $content = "Nom de l'enfant;Prénom de l'enfant;Date de naissance;Catégorie;Classe;École;Nom du responsable légal;Téléphone du responsable légal;Email de contact;Bénévole\n"
            . "Lefèvre;Inès;02/04/2020;Fille;CP;École des Tilleuls;Marc Lefèvre;0601020304;marc@example.org;oui\n";
        file_put_contents($csv, mb_convert_encoding($content, 'Windows-1252', 'UTF-8'));

        $this->admin->upload('/registrations/import', 'file', $csv, 'inscriptions.csv');
        self::assertStringContainsString('<strong>1</strong> nouveau', $this->admin->lastBody);
        self::assertStringContainsString('Inès LEFÈVRE', $this->admin->lastBody);
    }

    public function testExport(): void
    {
        $this->add();
        $response = $this->admin->get('/registrations/export');

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaderLine('Content-Type'));
        $file = $this->dataDir . '/export.xlsx';
        file_put_contents($file, (string) $response->getBody());
        $rows = IOFactory::load($file)->getActiveSheet()->toArray();
        self::assertSame('Dossard', $rows[0][0]);
        self::assertSame(['1', 'inscrit', 'MARTIN', 'Zoé'], [$rows[1][0], $rows[1][1], $rows[1][2], $rows[1][3]]);
        self::assertSame('06 11 22 33 44', $rows[1][9]);
    }

    private ?\Psr\Http\Message\ResponseInterface $last = null;

    /**
     * @param array<string, string> $overrides
     */
    private function add(array $overrides = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->last = $this->admin->post('/registrations/new', self::form($overrides));
    }

    private function lastResponse(): \Psr\Http\Message\ResponseInterface
    {
        return $this->last ?? throw new \LogicException('No request yet.');
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function form(array $overrides = []): array
    {
        return [
            'last_name' => 'Martin',
            'first_name' => 'Zoé',
            'birth_date' => '2017-03-14',
            'gender' => 'girl',
            'school' => 'École des Tilleuls',
            'level' => 'CM1',
            'guardian_name' => 'Anne Martin',
            'guardian_phone' => '0611223344',
            'guardian_email' => 'anne@example.org',
            'volunteer' => '1',
            ...$overrides,
        ];
    }

    private function sampleFile(): string
    {
        $config = ConfigRepository::loadFile(\dirname(__DIR__, 2) . '/config.example/competition.toml');
        $file = $this->dataDir . '/sample.xlsx';
        file_put_contents($file, (new ImportSample($config))->xlsx());

        return $file;
    }
}
