<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 8a: results and rankings per category, missing events, clearing a
 * child's results at an event, test data with results.
 * Example configuration: long jump for CE2-CM2 (2 attempts, bigger is better).
 */
final class ResultsTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-results-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', 'CM1', '2017-03-14');   // bib 1
        $this->add('Petit', 'Léa', 'CM1', '2017-06-02');    // bib 2
        $this->add('Roux', 'Iris', 'CM1', '2017-01-20');    // bib 3, not checked in
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '1']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testRankingAndEventDetailAreComputedFromTheAttempts(): void
    {
        $this->record(1, '345');
        $this->record(1, '402');
        $this->record(2, '280');

        $this->admin->get('/results?category=CM1-girl');
        self::assertStringContainsString('Résultats et classements', $this->admin->lastBody);
        self::assertStringContainsString('Zoé MARTIN', $this->admin->lastBody);
        // Long jump: 4,02 m is the best (1000 points), 2,80 m the worst (floor 100).
        self::assertMatchesRegularExpression('#<td class="num">1</td>\s*<td>\s*<span class="bib">1</span> Zoé MARTIN#', $this->admin->lastBody);
        self::assertStringContainsString('<span class="best">4,02 m</span>', $this->admin->lastBody);
        self::assertStringContainsString('épreuves en attente', $this->admin->lastBody);
        self::assertStringContainsString('Absents : 3', $this->admin->lastBody);
        self::assertStringContainsString('href="/entry/long_jump/1"', $this->admin->lastBody, 'Each line leads to the correction screen.');

        $this->admin->get('/dashboard');
        self::assertStringContainsString('href="/results"', $this->admin->lastBody);
        self::assertStringContainsString('href="/missing"', $this->admin->lastBody);
    }

    public function testMissingEventsByWorkshopAndByChild(): void
    {
        $this->record(1, '345');
        $this->admin->get('/missing?workshop=long_jump');
        self::assertStringContainsString('Léa PETIT', $this->admin->lastBody);
        self::assertStringNotContainsString('Zoé MARTIN', $this->admin->lastBody);
        self::assertStringNotContainsString('Iris ROUX', $this->admin->lastBody, 'Only present children are called.');

        $this->admin->get('/missing?view=child&category=CM1-girl');
        self::assertMatchesRegularExpression('#Zoé MARTIN.*?</td>\s*<td>[^<]*Sprint 40 m#s', $this->admin->lastBody);
        $row = explode('</tr>', explode('Zoé MARTIN', $this->admin->lastBody)[1] ?? '')[0];
        self::assertStringNotContainsString('Saut en longueur', $row, 'An event already done is not missing.');
    }

    public function testOrganizerCanClearAChildsResultsAtAnEvent(): void
    {
        $this->record(1, '345');
        $this->record(1, '402');
        $this->admin->get('/entry/long_jump/1');
        self::assertStringContainsString('Effacer tous ses résultats', $this->admin->lastBody);
        $this->admin->post('/entry/long_jump/1', ['op' => 'clear']);
        $this->admin->get('/entry/long_jump/1');
        self::assertStringContainsString('2 résultat(s) effacé(s)', $this->admin->lastBody);
        self::assertStringContainsString('Essai 1 sur 2', $this->admin->lastBody, 'The attempts are available again.');
        self::assertStringNotContainsString('Effacer tous ses résultats', $this->admin->lastBody);
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Résultats d&#039;un dossard effacés', $this->admin->lastBody);
    }

    public function testRefereeCannotClearNorSeeRankings(): void
    {
        $this->record(1, '345');
        $referee = $this->admin->otherBrowser();
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $referee->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $referee->post('/referee/name', ['first_name' => 'Paul']);
        $referee->get('/entry/long_jump/1');
        self::assertStringNotContainsString('Effacer tous ses résultats', $referee->lastBody);
        $referee->post('/entry/long_jump/1', ['op' => 'clear']);
        $this->admin->get('/entry/long_jump/1');
        self::assertStringContainsString('<strong>3,45 m</strong>', $this->admin->lastBody);
        self::assertSame(403, $referee->get('/results')->getStatusCode());
    }

    public function testTestDataCanComeWithResults(): void
    {
        $this->admin->get('/admin/test-data');
        $this->admin->post('/admin/test-data', ['confirmation' => 'EFFACER', 'with_results' => '1']);
        $this->admin->get('/admin/test-data');
        self::assertMatchesRegularExpression('/dont 90 présents, 5 en liste d&#039;attente, [1-9][0-9]+ résultats/', $this->admin->lastBody);
        $this->admin->get('/results?category=CM2-boy');
        self::assertStringContainsString('class="podium"', $this->admin->lastBody);
        // The ceremony screen's grid must not apply to the ranking rows of the podium.
        self::assertDoesNotMatchRegularExpression('#^\.podium\s*\{#m', (string) file_get_contents(__DIR__ . '/../../public/assets/css/app.css'));
        $this->admin->get('/missing');
        self::assertStringContainsString('missing-list', $this->admin->lastBody);
    }

    private function record(int $bib, string $value): void
    {
        $this->admin->get('/entry/long_jump/' . $bib);
        preg_match('#name="entry_id" value="([a-f0-9]{32})"#', $this->admin->lastBody, $m);
        $this->admin->post('/entry/long_jump/' . $bib, ['op' => 'record', 'kind' => 'value', 'value' => $value, 'entry_id' => $m[1] ?? '', 'confirm' => '1']);
    }

    private function add(string $lastName, string $firstName, string $level, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'Springfield Elementary', 'level' => $level, 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
