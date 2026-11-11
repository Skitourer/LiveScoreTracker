<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of step 5: result entry at the workshops.
 * Example configuration: long jump for CE2-CM2 (2 attempts, lenient fouls,
 * 0.50–5.00 m), hockey for both groups (time, "goal scored" −3 s).
 */
final class EntryTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-entry-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', 'CM1', '2017-03-14');   // bib 1, senior
        $this->add('Durand', 'Hugo', 'CP', '2020-05-10');   // bib 2, junior
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testRefereeEntersAttemptsWithFoulsAndWarnings(): void
    {
        $phone = $this->referee('Paul');

        // Choose the workshop; it is remembered on the phone.
        self::assertSame(200, $phone->get('/entry')->getStatusCode());
        self::assertStringContainsString('Saut en longueur', $phone->lastBody);
        $phone->get('/entry/long_jump');
        self::assertSame('/entry/long_jump', $phone->get('/entry')->getHeaderLine('Location'));

        // Blocking checks on the bib.
        self::assertSame(422, $phone->get('/entry/long_jump?bib=99')->getStatusCode());
        self::assertStringContainsString('Dossard 99 inconnu', $phone->lastBody);
        $phone->get('/entry/long_jump?bib=2');
        self::assertStringContainsString('pas du bon niveau', $phone->lastBody);
        self::assertSame('/entry/long_jump/1', $phone->get('/entry/long_jump?bib=1')->getHeaderLine('Location'));

        $phone->get('/entry/long_jump/1');
        self::assertStringContainsString('Zoé M.', $phone->lastBody);
        self::assertStringNotContainsString('MARTIN', $phone->lastBody, 'First name and initial only.');
        self::assertStringContainsString('Essai 1 sur 2', $phone->lastBody);

        // Attempt 1: 3.45 m, back to the bib field.
        self::assertSame('/entry/long_jump', $this->record($phone, ['value' => '345'])->getHeaderLine('Location'));
        $phone->get('/entry/long_jump');
        self::assertStringContainsString('Zoé M. (1) : 3,45 m — essai 1/2 enregistré', $phone->lastBody);

        // Lenient foul: recorded, but the same attempt number is asked again.
        $this->record($phone, ['kind' => 'foul']);
        $phone->get('/entry/long_jump/1');
        self::assertStringContainsString('Essai 2 sur 2', $phone->lastBody);
        self::assertStringContainsString('ne compte pas', $phone->lastBody);

        // 6.00 m is outside the plausible range: confirm first.
        self::assertSame(422, $this->record($phone, ['value' => '600'])->getStatusCode());
        self::assertStringContainsString('Résultat inhabituel', $phone->lastBody);
        self::assertStringContainsString('6,00 m', $phone->lastBody);
        $entryId = self::match('#name="entry_id" value="([a-f0-9]{32})"#', $phone->lastBody);
        $this->record($phone, ['value' => '600', 'confirm' => '1', 'entry_id' => $entryId]);
        // The same entry sent twice is saved once.
        $this->record($phone, ['value' => '600', 'confirm' => '1', 'entry_id' => $entryId]);
        $phone->get('/entry/long_jump/1');
        self::assertStringContainsString('Essais terminés (2/2)', $phone->lastBody);
        self::assertStringContainsString('Meilleur résultat : 6,00 m', $phone->lastBody);
        self::assertSame(1, substr_count($phone->lastBody, '<strong>6,00 m</strong>'));
        self::assertStringContainsString('à vérifier', $phone->lastBody);
        self::assertStringContainsString('Paul', $phone->lastBody);

        // A third attempt is possible but must be confirmed.
        $this->record($phone, ['value' => '300']);
        self::assertStringContainsString('déjà fait ses 2 essais', $phone->lastBody);
    }

    public function testCorrectionAndDeletionKeepTheHistory(): void
    {
        $phone = $this->referee('Paul');
        $this->record($phone, ['value' => '345']);
        $phone->get('/entry/long_jump/1');
        $id = self::match('#\?correct=(\d+)#', $phone->lastBody);

        $phone->get('/entry/long_jump/1?correct=' . $id);
        self::assertStringContainsString('value="345"', $phone->lastBody);
        $phone->post('/entry/long_jump/1', ['op' => 'correct', 'attempt_id' => $id, 'value' => '354', 'kind' => 'value']);
        $phone->get('/entry/long_jump/1');
        self::assertStringContainsString('corrigé en 3,54 m', $phone->lastBody);
        self::assertStringContainsString('Avant correction', $phone->lastBody);
        self::assertStringContainsString('<strong>3,45 m</strong>', $phone->lastBody);
        self::assertStringContainsString('Essai 2 sur 2', $phone->lastBody);

        $current = self::match('#\?correct=(\d+)#', $phone->lastBody);
        self::assertNotSame($id, $current);
        $phone->post('/entry/long_jump/1', ['op' => 'delete', 'attempt_id' => $current]);
        $phone->get('/entry/long_jump/1');
        self::assertStringContainsString('Essai supprimé', $phone->lastBody);
        self::assertStringContainsString('Supprimé le', $phone->lastBody);
        self::assertStringContainsString('Essai 1 sur 2', $phone->lastBody);
    }

    public function testModifiersAndChildNotCheckedIn(): void
    {
        $this->admin->get('/entry/hockey/2');
        self::assertStringContainsString('pas été pointé à l&#039;accueil', $this->admin->lastBody);

        // Not checked in: a warning to confirm.
        $this->admin->post('/entry/hockey/2', ['op' => 'record', 'kind' => 'value', 'value' => '2000', 'mod_goal_scored' => '1']);
        self::assertStringContainsString('<li>Cet enfant n&#039;a pas été pointé', $this->admin->lastBody);

        $this->admin->post('/entry/hockey/2', ['op' => 'mark_present']);
        $this->admin->post('/entry/hockey/2', ['op' => 'record', 'kind' => 'value', 'value' => '2000', 'mod_goal_scored' => '1']);
        $this->admin->get('/entry/hockey');
        self::assertStringContainsString('Hugo D. (2) : 17,00 s', $this->admin->lastBody);
        $this->admin->get('/entry/hockey/2');
        self::assertStringContainsString('20,00 s, But marqué', $this->admin->lastBody);
    }

    public function testEntryOnlyWhileTheCompetitionRuns(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'preparation']);
        $this->admin->get('/entry/long_jump/1');
        self::assertStringNotContainsString('name="value"', $this->admin->lastBody);
        $this->record($this->admin, ['value' => '345', 'entry_id' => str_repeat('a', 32)]);
        $this->admin->get('/entry/long_jump/1');
        self::assertStringContainsString('s&#039;ouvre quand la compétition', $this->admin->lastBody);
        self::assertStringContainsString('Aucun essai', $this->admin->lastBody);
    }

    public function testRemainingBibsAndHelp(): void
    {
        $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->get('/entry/hockey/remaining');
        self::assertStringContainsString('Zoé M.', $this->admin->lastBody);
        self::assertStringContainsString('Hugo D.', $this->admin->lastBody);

        // Hockey: 2 attempts. One done: still listed, with 1 / 2.
        $this->record($this->admin, ['value' => '2000'], 'hockey', 1);
        $this->admin->get('/entry/hockey/remaining');
        self::assertStringContainsString('1 / 2', $this->admin->lastBody);
        $this->record($this->admin, ['value' => '1900'], 'hockey', 1);
        $this->admin->get('/entry/hockey/remaining');
        self::assertStringNotContainsString('href="/entry/hockey/1"', $this->admin->lastBody);
        self::assertStringContainsString('<strong>1</strong> terminés', $this->admin->lastBody);
        $this->admin->get('/entry/hockey/remaining?q=hu');
        self::assertStringContainsString('Hugo D.', $this->admin->lastBody);

        // A bib typed in the search gives the real reason when it is not listed.
        $this->admin->get('/entry/long_jump/remaining?q=2');
        self::assertStringContainsString('pas du bon niveau', $this->admin->lastBody);
        self::assertStringNotContainsString('ont terminé cet atelier', $this->admin->lastBody);
        $this->admin->get('/entry/hockey/remaining?q=1');
        self::assertStringContainsString('Le dossard 1 a déjà terminé', $this->admin->lastBody);
        $this->admin->get('/entry/hockey/remaining?q=99');
        self::assertStringContainsString('Dossard 99 inconnu', $this->admin->lastBody);
        $this->admin->get('/entry/hockey/remaining?q=zz');
        self::assertStringContainsString('Aucun enfant restant ne correspond', $this->admin->lastBody);

        $this->admin->get('/entry/hockey/help');
        self::assertStringContainsString('<h2>Matériel</h2>', $this->admin->lastBody);
        self::assertStringContainsString('href="tel:+33600000001"', $this->admin->lastBody);
    }

    public function testOrganizerCanEnterButNotAnonymous(): void
    {
        $organizer = AdminSession::organizer($this->admin);
        self::assertSame(200, $organizer->get('/entry/long_jump/1')->getStatusCode());
        $anonymous = $this->admin->otherBrowser();
        self::assertStringStartsWith('/login', $anonymous->get('/entry/long_jump/1')->getHeaderLine('Location'));
    }

    private function referee(string $name): WebClient
    {
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone = $this->admin->otherBrowser();
        $phone->get('/login');
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone->post('/referee/name', ['first_name' => $name]);

        return $phone;
    }

    /**
     * @param array<string, string> $form
     */
    private function record(WebClient $client, array $form, string $workshop = 'long_jump', int $bib = 1): \Psr\Http\Message\ResponseInterface
    {
        if (!isset($form['entry_id'])) {
            $client->get("/entry/{$workshop}/{$bib}");
            $form['entry_id'] = self::match('#name="entry_id" value="([a-f0-9]{32})"#', $client->lastBody);
        }

        return $client->post("/entry/{$workshop}/{$bib}", ['op' => 'record', 'kind' => 'value', ...$form]);
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

    private static function match(string $pattern, string $subject): string
    {
        if (preg_match($pattern, $subject, $m) !== 1 || !isset($m[1])) {
            self::fail("Pattern {$pattern} not found.");
        }

        return $m[1];
    }
}
