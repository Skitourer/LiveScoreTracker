<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/**
 * Step 10a: documents page, rescue sheets, podiums, rankings and exports (example configuration).
 */
final class DocumentsTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-documents-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        foreach (['Zoé' => '2017-03-14', 'Léa' => '2017-05-02', 'Iris' => '2017-01-20', 'Anna' => '2017-02-11'] as $first => $birth) {
            $this->add('Martin', $first, $birth);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testRescueSheetsAndFreshness(): void
    {
        $this->admin->get('/documents');
        self::assertStringContainsString('Feuilles de secours (PDF)', $this->admin->lastBody);
        self::assertStringNotContainsString('Dernière impression', $this->admin->lastBody);

        $response = $this->admin->get('/documents/rescue-sheets.pdf');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $text = $this->pdfText($this->admin->lastBody);
        if ($text !== null) {
            self::assertStringContainsString('Saut en longueur', $text);
            self::assertStringContainsString('Arbitre :', $text);
            self::assertStringContainsString('État au', $text);
            self::assertMatchesRegularExpression('#1\s+MARTIN Zoé.*2\s+MARTIN Léa#s', $text, 'Sorted by bib.');
        }

        $this->admin->get('/documents');
        self::assertStringContainsString('aucune inscription modifiée depuis', $this->admin->lastBody);

        // A child registered after the printing: the page asks to print again.
        $this->admin->post('/registrations/new', $this->child('Durand', 'Nina', '2017-06-01'));
        $this->admin->get('/documents');
        self::assertStringContainsString('1 inscription(s) ajoutée(s) ou modifiée(s) depuis', $this->admin->lastBody);

        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Document téléchargé', $this->admin->lastBody);
    }

    public function testProvisionalPodiumsThenFinal(): void
    {
        $this->enterJumps();
        $this->admin->get('/documents');
        self::assertStringContainsString('podiums et classements sont provisoires', $this->admin->lastBody);
        $text = $this->pdfText($this->download('podiums.pdf'));
        if ($text !== null) {
            self::assertStringContainsString('PROVISOIRE', $text);
            self::assertMatchesRegularExpression('#1\s+Léa MARTIN.*2\s+Zoé MARTIN.*3\s+Iris MARTIN#s', $text);
            self::assertStringNotContainsString('Anna', $text, 'Only the podium.');
        }

        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $this->admin->get('/documents');
        self::assertStringNotContainsString('podiums et classements sont provisoires', $this->admin->lastBody);
        $text = $this->pdfText($this->download('podiums.pdf'));
        if ($text !== null) {
            self::assertStringNotContainsString('PROVISOIRE', $text);
        }
        self::assertStringStartsWith('%PDF-', $this->download('rankings.pdf'));
        self::assertStringStartsWith('%PDF-', $this->download('help-sheets.pdf'));
    }

    public function testExcelExports(): void
    {
        $this->enterJumps();

        $rankings = $this->spreadsheet($this->download('rankings.xlsx'));
        $sheet = $rankings->getSheetByName('Filles CM1');
        self::assertNotNull($sheet);
        self::assertSame('Rang', $sheet->getCell('A1')->getValue());
        self::assertSame([1, 2, 'MARTIN', 'Léa'], [$sheet->getCell('A2')->getValue(), $sheet->getCell('B2')->getValue(), $sheet->getCell('C2')->getValue(), $sheet->getCell('D2')->getValue()]);

        $results = $this->spreadsheet($this->download('results.xlsx'))->getActiveSheet();
        self::assertSame('Dossard', $results->getCell('A1')->getValue());
        self::assertSame(1, $results->getCell('A2')->getValue());
        self::assertSame('3,00 m', $results->getCell('I2')->getValue());
        self::assertSame(5, $results->getHighestRow(), 'One line per attempt.');
    }

    public function testAccess(): void
    {
        self::assertSame(404, $this->admin->get('/documents/nothing.pdf')->getStatusCode());
        $organizer = AdminSession::organizer($this->admin);
        self::assertSame(200, $organizer->get('/documents')->getStatusCode());
        self::assertSame(200, $organizer->get('/documents/podiums.pdf')->getStatusCode());

        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone = $this->admin->otherBrowser();
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone->post('/referee/name', ['first_name' => 'Paul']);
        self::assertSame(403, $phone->get('/documents/rescue-sheets.pdf')->getStatusCode());
    }

    private function enterJumps(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        foreach (['1' => '300', '2' => '350', '3' => '250', '4' => '200'] as $bib => $jump) {
            $this->admin->post('/check-in/' . $bib, ['mark' => 'checked_in', 'done' => '1']);
            $this->admin->get('/entry/long_jump/' . $bib);
            $id = preg_match('#name="entry_id" value="([a-f0-9]{32})"#', $this->admin->lastBody, $m) === 1 ? $m[1] : '';
            $this->admin->post('/entry/long_jump/' . $bib, ['op' => 'record', 'kind' => 'value', 'value' => $jump, 'entry_id' => $id]);
        }
    }

    private function download(string $name): string
    {
        self::assertSame(200, $this->admin->get('/documents/' . $name)->getStatusCode());

        return $this->admin->lastBody;
    }

    private function spreadsheet(string $content): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $file = $this->dataDir . '/export.xlsx';
        file_put_contents($file, $content);

        return IOFactory::load($file);
    }

    /**
     * Text of a PDF, or null when pdftotext is not installed (the content checks are then skipped).
     */
    private function pdfText(string $pdf): ?string
    {
        self::assertStringStartsWith('%PDF-', $pdf);
        exec('command -v pdftotext', $out, $code);
        if ($code !== 0) {
            return null;
        }
        $file = $this->dataDir . '/document.pdf';
        file_put_contents($file, $pdf);
        exec('pdftotext -layout ' . escapeshellarg($file) . ' -', $lines);

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    private function child(string $lastName, string $firstName, string $birthDate): array
    {
        return [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'Springfield Elementary', 'level' => 'CM1', 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ];
    }

    private function add(string $lastName, string $firstName, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', $this->child($lastName, $firstName, $birthDate));
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
