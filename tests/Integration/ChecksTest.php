<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 8b: control board (results to check, ties broken by hand), closing
 * and reopening the competition, entries received after the closing.
 * Example configuration: long jump for CE2-CM2 (2 attempts, bigger is better).
 */
final class ChecksTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-checks-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', '2017-03-14');   // bib 1
        $this->add('Petit', 'Léa', '2017-03-14');    // bib 2, same birth date
        $this->add('Roux', 'Iris', '2017-01-20');    // bib 3, not checked in
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
        $this->admin->post('/check-in/2', ['mark' => 'checked_in', 'done' => '1']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testResultToCheckCanBeVerifiedAndAgainAfterACorrection(): void
    {
        $this->record($this->admin, 3, '345');   // not checked in: "to check"
        $this->admin->get('/checks');
        self::assertStringContainsString('Iris ROUX', $this->admin->lastBody);
        self::assertStringContainsString('non pointé à l&#039;accueil', $this->admin->lastBody);
        self::assertStringContainsString('href="/entry/long_jump/3"', $this->admin->lastBody);
        $this->admin->get('/dashboard');
        self::assertMatchesRegularExpression('#href="/checks">Contrôles <span class="badge warning">1</span>#', $this->admin->lastBody);

        $organizer = AdminSession::organizer($this->admin);
        $organizer->get('/checks');
        $attempt = self::match('#name="attempt" value="(\d+)"#', $organizer->lastBody);
        $organizer->post('/checks', ['op' => 'verify', 'attempt' => $attempt]);
        $organizer->get('/checks');
        self::assertStringContainsString('Aucun résultat à vérifier.', $organizer->lastBody);
        self::assertStringNotContainsString('Enregistrer l&#039;ordre', $organizer->lastBody);
        $this->admin->get('/entry/long_jump/3');
        self::assertStringContainsString('vérifié par orga', $this->admin->lastBody);
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Résultat marqué vérifié', $this->admin->lastBody);

        // A correction is a new version: to check again.
        $this->admin->post('/entry/long_jump/3', ['op' => 'correct', 'attempt_id' => $attempt, 'value' => '354', 'kind' => 'value', 'confirm' => '1']);
        $this->admin->get('/checks');
        self::assertStringContainsString('3,54 m', $this->admin->lastBody);
        self::assertStringNotContainsString('Aucun résultat à vérifier.', $this->admin->lastBody);
    }

    public function testAdminBreaksAnUnbreakableTieByHand(): void
    {
        // Same jump, same birth date: no criterion can separate them.
        $this->record($this->admin, 1, '300');
        $this->record($this->admin, 2, '300');
        $this->admin->get('/results?category=CM1-girl');
        self::assertSame(2, substr_count($this->admin->lastBody, 'égalité à trancher'));
        $this->admin->get('/checks');
        self::assertStringContainsString('Aucune égalité à trancher.', $this->admin->lastBody, 'Other events to do: the tie may still change.');

        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $this->admin->get('/checks');
        self::assertStringContainsString('rang 1 · 1000 points', $this->admin->lastBody);
        $this->admin->post('/checks', ['op' => 'tie', 'category' => 'CM1-girl', 'position' => ['1' => '1', '2' => '1']]);
        $this->admin->get('/checks');
        self::assertStringContainsString('Ordre impossible', $this->admin->lastBody);

        $this->admin->post('/checks', ['op' => 'tie', 'category' => 'CM1-girl', 'position' => ['1' => '2', '2' => '1']]);
        $this->admin->get('/results?category=CM1-girl');
        self::assertStringNotContainsString('égalité à trancher', $this->admin->lastBody);
        self::assertMatchesRegularExpression('#<td class="num">1</td>\s*<td>\s*<span class="bib">2</span> Léa PETIT\s*<span class="badge">départagé : décision de l&\#039;arbitre principal#', $this->admin->lastBody);
        self::assertMatchesRegularExpression('#<td class="num">2</td>\s*<td>\s*<span class="bib">1</span> Zoé MARTIN#', $this->admin->lastBody);

        $this->admin->get('/checks');
        self::assertStringContainsString('égalité à 1000 points, tranchée', $this->admin->lastBody);
        $this->admin->post('/checks', ['op' => 'tie_reset', 'category' => 'CM1-girl', 'bibs' => '2,1']);
        $this->admin->get('/results?category=CM1-girl');
        self::assertSame(2, substr_count($this->admin->lastBody, 'égalité à trancher'));
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Égalité tranchée', $this->admin->lastBody);
    }

    public function testClosingRecallsWhatIsPendingThenReopening(): void
    {
        $this->record($this->admin, 1, '300');

        // Closing without going through the recap page is not possible.
        $response = $this->admin->post('/admin/phase', ['phase' => 'finished']);
        self::assertSame('/admin/phase?close=1', $response->getHeaderLine('Location'));
        $this->admin->get('/admin/phase?close=1');
        self::assertStringContainsString('Enfants présents avec des épreuves manquantes : 2', $this->admin->lastBody);
        self::assertStringContainsString('Léa PETIT', $this->admin->lastBody);
        self::assertStringContainsString('Clôturer quand même', $this->admin->lastBody);

        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);
        $this->admin->get('/admin/phase');
        self::assertStringContainsString('La compétition est clôturée.', $this->admin->lastBody);
        self::assertStringContainsString('Rouvrir la compétition', $this->admin->lastBody);
        $this->admin->get('/results?category=CM1-girl');
        self::assertStringContainsString('Compétition terminée', $this->admin->lastBody);
        $this->admin->get('/entry/long_jump/2');
        self::assertStringContainsString('la saisie est fermée', $this->admin->lastBody);

        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->get('/admin/phase');
        self::assertStringContainsString('La compétition est rouverte.', $this->admin->lastBody);
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('missing_children', $this->admin->lastBody);
    }

    public function testEntriesSentAfterTheClosingWaitForADecision(): void
    {
        $phone = $this->referee();
        $phone->get('/entry/long_jump/pack');
        $pack = json_decode($phone->lastBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($pack);
        $csrf = \is_string($pack['csrf'] ?? null) ? $pack['csrf'] : '';
        $variant = 'long_jump.senior';

        $this->admin->get('/admin/phase?close=1');
        $this->admin->post('/admin/phase', ['phase' => 'finished', 'confirm' => '1']);

        // The phone still sends what it kept; nothing else works for it.
        $accepted = self::entry(1, $variant, 410);
        $refused = self::entry(2, $variant, 380);
        $unknown = self::entry(99, $variant, 380);
        $phone->postJson('/entry/long_jump/sync', ['entries' => [$accepted, $refused, $unknown]], $csrf);
        $results = json_decode($phone->lastBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($results);
        self::assertSame(['late', 'late', 'rejected'], array_column(\is_array($results['results'] ?? null) ? $results['results'] : [], 'status'));
        $phone->postJson('/entry/long_jump/sync', ['entries' => [$accepted]], $csrf);
        self::assertSame(302, $phone->get('/entry')->getStatusCode(), 'Other pages ask to log in again.');

        $this->admin->get('/results?category=CM1-girl');
        self::assertStringNotContainsString('4,10 m', $this->admin->lastBody, 'Not counted before a decision.');
        $this->admin->get('/checks');
        self::assertSame(2, substr_count($this->admin->lastBody, 'name="op" value="accept"'), 'Sent twice, kept once.');

        $ids = [];
        preg_match_all('#name="late" value="(\d+)"#', $this->admin->lastBody, $ids);
        $this->admin->post('/checks', ['op' => 'accept', 'late' => $ids[1][0] ?? '']);
        $this->admin->post('/checks', ['op' => 'refuse', 'late' => $ids[1][2] ?? '']);
        $this->admin->get('/checks');
        self::assertStringContainsString('Aucune saisie en attente.', $this->admin->lastBody);
        $this->admin->get('/results?category=CM1-girl');
        self::assertStringContainsString('4,10 m', $this->admin->lastBody);
        self::assertStringNotContainsString('3,80 m', $this->admin->lastBody);
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Saisie tardive acceptée', $this->admin->lastBody);
        self::assertStringContainsString('Saisie tardive refusée', $this->admin->lastBody);

        // Reopened: the referee phone works again.
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        self::assertSame(200, $phone->get('/entry')->getStatusCode());
    }

    private function referee(): WebClient
    {
        $this->admin->post('/admin/referee', ['op' => 'create', 'username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone = $this->admin->otherBrowser();
        $phone->post('/login', ['username' => 'arbitre', 'password' => 'whistle-2027']);
        $phone->post('/referee/name', ['first_name' => 'Paul']);
        self::assertSame(200, $phone->get('/entry')->getStatusCode());

        return $phone;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(int $bib, string $variant, int $measured): array
    {
        return ['id' => bin2hex(random_bytes(16)), 'bib' => $bib, 'variant' => $variant, 'measured' => $measured, 'modifiers' => [], 'details' => [], 'warnings' => [], 'at' => (time() - 300) * 1000];
    }

    private function record(WebClient $client, int $bib, string $value): void
    {
        $client->get('/entry/long_jump/' . $bib);
        $id = self::match('#name="entry_id" value="([a-f0-9]{32})"#', $client->lastBody);
        $client->post('/entry/long_jump/' . $bib, ['op' => 'record', 'kind' => 'value', 'value' => $value, 'entry_id' => $id, 'confirm' => '1']);
    }

    private static function match(string $pattern, string $body): string
    {
        self::assertSame(1, preg_match($pattern, $body, $m), 'Not found: ' . $pattern);

        return $m[1];
    }

    private function add(string $lastName, string $firstName, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'Springfield Elementary', 'level' => 'CM1', 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
