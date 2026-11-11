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

    public function testManualRegistrationGetsTheNextBibWhichIsNeverReused(): void
    {
        $first = $this->add(['last_name' => 'martin', 'first_name' => 'zoé']);
        self::assertSame('/registrations/1', $first->getHeaderLine('Location'));
        $this->admin->get('/registrations/1');
        self::assertStringContainsString('<span class="bib">1</span> Zoé MARTIN', $this->admin->lastBody);

        $this->add(['last_name' => 'Durand', 'first_name' => 'Hugo', 'gender' => 'boy']);
        // Withdraw bib 2, then add a third child: it gets 3, never 2 again.
        $this->admin->post('/registrations/2', ['op' => 'status', 'status' => 'withdrawn']);
        $this->add(['last_name' => 'Petit', 'first_name' => 'Léo', 'gender' => 'boy']);
        $this->admin->get('/registrations/3');
        self::assertStringContainsString('<span class="bib">3</span>', $this->admin->lastBody);

        $this->admin->get('/registrations');
        self::assertStringContainsString('<strong>2</strong> / 100', $this->admin->lastBody);
        self::assertStringNotContainsString('DURAND', $this->admin->lastBody, 'Withdrawn children are hidden by default');
        $this->admin->get('/registrations?status=all');
        self::assertStringContainsString('DURAND', $this->admin->lastBody);
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

    public function testCorrectionIsRecordedInTheHistory(): void
    {
        $this->add();
        $this->admin->post('/registrations/1', ['op' => 'update', ...self::form(['school' => 'Shelbyville Elementary', 'volunteer' => ''])]);
        $this->admin->get('/registrations/1');
        self::assertStringContainsString('Fiche corrigée', $this->admin->lastBody);
        self::assertStringContainsString('Springfield Elementary → Shelbyville Elementary', $this->admin->lastBody);
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

        $done = $this->admin->post('/registrations/import', ['op' => 'confirm']);
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
        $content = "Child last name;Child first name;Child birth date;Gender;Class;School;Guardian full name;Guardian phone;Contact email;Volunteer\n"
            . "Lefèvre;Inès;02/04/2020;Fille;CP;Springfield Elementary;Marc Lefèvre;0601020304;marc@example.org;oui\n";
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

    /**
     * @param array<string, string> $overrides
     */
    private function add(array $overrides = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->admin->post('/registrations/new', self::form($overrides));
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
            'school' => 'Springfield Elementary',
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
