<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 9b: participant space (bib + birth date) and progress screens.
 * Example configuration: long jump for CE2-CM2 (2 attempts).
 */
final class ParticipantProgressTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-participant-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', '2017-03-14');   // bib 1
        $this->add('Petit', 'Léa', '2017-05-02');    // bib 2
        $this->add('Roux', 'Iris', '2017-01-20');    // bib 3, never checked in
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testFamilyFollowsTheChildAlongThePhases(): void
    {
        $family = $this->admin->otherBrowser();
        self::assertSame(200, $family->get('/participant')->getStatusCode());
        self::assertStringContainsString('name="birth_date"', $family->lastBody);

        self::assertSame(401, $family->post('/participant', ['bib' => '1', 'birth_date' => '2017-03-15'])->getStatusCode());
        self::assertStringContainsString('Dossard ou date de naissance incorrects.', $family->lastBody);

        $family->post('/participant', ['bib' => '1', 'birth_date' => '2017-03-14']);
        $family->get('/participant');
        self::assertStringContainsString('<h1>Zoé MARTIN</h1>', $family->lastBody);
        self::assertStringContainsString('Saut en longueur', $family->lastBody, 'Preparation: the events of the group.');

        $this->admin->post('/admin/phase', ['phase' => 'running']);
        foreach ([1, 2] as $bib) {
            $this->admin->post('/check-in/' . $bib, ['mark' => 'checked_in', 'done' => '1']);
        }
        $this->record(1, '312');
        $this->record(1, '');
        $this->record(2, '345');
        $family->get('/participant');
        self::assertStringContainsString('3,12 m · nul', $family->lastBody, 'Raw results while running.');
        self::assertStringContainsString('Épreuves à faire', $family->lastBody);
        self::assertStringNotContainsString('Total : ', $family->lastBody, 'No score before the closing.');
        self::assertStringNotContainsString('PETIT', $family->lastBody);

        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $family->get('/participant');
        self::assertStringContainsString('Total : ', $family->lastBody);
        self::assertStringContainsString('2e sur 2', $family->lastBody);
        self::assertStringContainsString('Classement Filles CM1', $family->lastBody);
        self::assertStringContainsString('Léa P.', $family->lastBody, 'Other children: first name + initial.');
        self::assertStringNotContainsString('PETIT', $family->lastBody);
        self::assertStringNotContainsString('Iris', $family->lastBody, 'Absent children are not in the ranking.');

        $family->post('/participant', ['op' => 'logout']);
        $family->get('/participant');
        self::assertStringContainsString('name="birth_date"', $family->lastBody);
    }

    public function testRepeatedFailuresAreBlocked(): void
    {
        $family = $this->admin->otherBrowser();
        for ($i = 0; $i < 5; ++$i) {
            $family->post('/participant', ['bib' => '2', 'birth_date' => '2010-01-0' . ($i + 1)]);
        }
        self::assertSame(429, $family->post('/participant', ['bib' => '2', 'birth_date' => '2017-05-02'])->getStatusCode());
    }

    public function testProgressScreens(): void
    {
        $screen = $this->admin->otherBrowser();
        $screen->get('/avancement');
        self::assertStringContainsString('La compétition n&#039;a pas encore commencé.', $screen->lastBody);

        $this->admin->post('/admin/phase', ['phase' => 'running']);
        foreach ([1, 2] as $bib) {
            $this->admin->post('/check-in/' . $bib, ['mark' => 'checked_in', 'done' => '1']);
        }
        $this->record(1, '312');
        self::assertSame(200, $screen->get('/avancement')->getStatusCode());
        $body = $screen->lastBody;
        self::assertStringContainsString('data-live="10"', $body);
        self::assertMatchesRegularExpression('#Saut en longueur</strong>\s*(<span class="badge warning">À privilégier</span>\s*)?<span class="progress-count">1 / 2 enfants#', $body);
        self::assertStringContainsString('1 / 12 enfants', $body, 'Overall: 2 present children × 6 events.');
        self::assertStringNotContainsString('Zoé', $body, 'No personal data.');
        self::assertStringNotContainsString('Déconnexion', $body);

        self::assertSame(302, $screen->get('/progress')->getStatusCode(), 'The detailed view needs a login.');
        $this->admin->get('/progress');
        self::assertStringContainsString('Avancement détaillé', $this->admin->lastBody);
        self::assertStringContainsString('<td class="num todo">1 / 2</td>', $this->admin->lastBody);
        $this->admin->get('/dashboard');
        self::assertStringContainsString('href="/progress"', $this->admin->lastBody);
        $this->admin->get('/');
        self::assertStringContainsString('href="/avancement"', $this->admin->lastBody);
        self::assertStringContainsString('href="/participant"', $this->admin->lastBody);
    }

    private function record(int $bib, string $value): void
    {
        $this->admin->get('/entry/long_jump/' . $bib);
        $id = preg_match('#name="entry_id" value="([a-f0-9]{32})"#', $this->admin->lastBody, $m) === 1 ? $m[1] : '';
        self::assertNotSame('', $id);
        $form = $value === ''
            ? ['op' => 'record', 'kind' => 'foul', 'entry_id' => $id, 'confirm' => '1']
            : ['op' => 'record', 'kind' => 'value', 'value' => $value, 'entry_id' => $id, 'confirm' => '1'];
        $this->admin->post('/entry/long_jump/' . $bib, $form);
    }

    private function add(string $lastName, string $firstName, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'École des Tilleuls', 'level' => 'CM1', 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
