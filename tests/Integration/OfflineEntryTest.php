<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 7a: the data a phone keeps for offline entry, and the entries it sends
 * later (spec § 8.8). Example configuration: sprint for both groups (1
 * attempt), hockey (2 attempts, "goal scored" −3 s), basketball for CE2-CM2.
 */
final class OfflineEntryTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-offline-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->add('Martin', 'Zoé', 'CM1', '2017-03-14');   // bib 1, senior
        $this->add('Durand', 'Hugo', 'CP', '2020-05-10');   // bib 2, junior, not checked in
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->post('/check-in/1', ['mark' => 'checked_in', 'done' => '1']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testPackHoldsWhatTheEntryScreensNeed(): void
    {
        $this->admin->get('/entry/hockey');
        self::assertStringContainsString('class="entry-app"', $this->admin->lastBody);
        self::assertStringContainsString('class="entry-fallback"', $this->admin->lastBody, 'The server screens stay without JavaScript.');

        $pack = $this->pack('hockey');
        self::assertSame('running', self::dig($pack, 'phase'));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', self::digString($pack, 'csrf'));
        self::assertSame(['id' => 'hockey', 'name' => 'Hockey', 'timed' => true, 'bibsPerRace' => 1], self::dig($pack, 'workshop'));
        $variant = self::dig($pack, 'variants', self::digString($pack, 'levels', 'CM1'));
        self::assertSame(2, self::dig($variant, 'attempts'));
        self::assertSame('goal_scored', self::dig($variant, 'modifiers', 0, 'id'));
        self::assertSame(-300, self::dig($variant, 'modifiers', 0, 'value'));
        self::assertSame([1, 2], [self::dig($pack, 'children', 0, 'bib'), self::dig($pack, 'children', 1, 'bib')]);
        self::assertSame(['Zoé M.', true], [self::dig($pack, 'children', 0, 'name'), self::dig($pack, 'children', 0, 'present')]);
        self::assertFalse(self::dig($pack, 'children', 1, 'present'));
        self::assertSame('{name} ({bib}) : {result} — essai {number}/{of} enregistré.', self::dig($pack, 'texts', 'entry.recorded'));
        self::assertStringNotContainsString('Martin', json_encode($pack, JSON_THROW_ON_ERROR), 'Only the initial of the last name leaves the server.');
        self::assertStringContainsString('help-page', self::digString($pack, 'help'), 'The help is kept on the phone.');
        self::assertSame('Suivi', self::dig($pack, 'texts', 'entry.tracking.title'));
    }

    public function testQueuedEntriesAreSavedOnceAndChecked(): void
    {
        $csrf = self::digString($this->pack('hockey'), 'csrf');
        $variant = self::dig($this->pack('hockey'), 'levels', 'CM1');
        $first = $this->entry(['bib' => 1, 'variant' => $variant, 'measured' => 2000, 'modifiers' => ['goal_scored' => 1], 'details' => ['source' => 'timer']]);
        $late = $this->entry(['bib' => 1, 'variant' => $variant, 'measured' => 1900, 'at' => (time() - 600) * 1000]);
        $unknown = $this->entry(['bib' => 99, 'variant' => $variant, 'measured' => 1900]);
        $wrongEvent = $this->entry(['bib' => 1, 'variant' => 'hockey/junior', 'measured' => 1900]);

        $response = $this->admin->postJson('/entry/hockey/sync', ['entries' => [$first, $late, $unknown, $wrongEvent]], $csrf);
        self::assertSame(200, $response->getStatusCode(), $this->admin->lastBody);
        $results = json_decode($this->admin->lastBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['saved', 'saved', 'rejected', 'rejected'], array_map(static fn(int $i): mixed => self::dig($results, 'results', $i, 'status'), [0, 1, 2, 3]));
        self::assertSame('entry.error.unknown_bib', self::dig($results, 'results', 2, 'reason'));
        self::assertSame('entry.error.other_group', self::dig($results, 'results', 3, 'reason'));

        // Sent again (the answer was lost): nothing more is stored.
        $this->admin->postJson('/entry/hockey/sync', ['entries' => [$first, $late]], $csrf);
        $this->admin->get('/entry/hockey/1');
        self::assertSame(1, substr_count($this->admin->lastBody, '<strong>17,00 s</strong>'));
        self::assertSame(1, substr_count($this->admin->lastBody, '<strong>19,00 s</strong>'));
        self::assertStringContainsString('chrono intégré', $this->admin->lastBody);

        // One attempt too many, sent by another phone: kept and marked "to check".
        $extra = $this->entry(['bib' => 1, 'variant' => $variant, 'measured' => 1800]);
        $this->admin->postJson('/entry/hockey/sync', ['entries' => [$extra]], $csrf);
        $this->admin->get('/entry/hockey/1');
        self::assertStringContainsString('<strong>18,00 s</strong>', $this->admin->lastBody);
        self::assertStringContainsString('à vérifier', $this->admin->lastBody);

        $pack = $this->pack('hockey');
        self::assertCount(3, (array) self::dig($pack, 'children', 0, 'attempts'));
        self::assertTrue(self::dig($pack, 'children', 0, 'attempts', 2, 'k'), 'Marked "to check" in the follow-up.');
        self::assertSame(1700, self::dig($pack, 'children', 0, 'attempts', 0, 'r'));
    }

    public function testCounterTotalIsRecomputedAndClosedPhaseIsReported(): void
    {
        $csrf = self::digString($this->pack('basketball'), 'csrf');
        $variant = self::dig($this->pack('basketball'), 'levels', 'CM1');
        $entry = $this->entry(['bib' => 1, 'variant' => $variant, 'measured' => 99, 'details' => ['source' => 'counter', 'actions' => ['basket' => 3, 'rim' => 2]]]);
        $this->admin->postJson('/entry/basketball/sync', ['entries' => [$entry]], $csrf);
        $this->admin->get('/entry/basketball/1');
        self::assertStringContainsString('<strong>13 pts</strong>', $this->admin->lastBody);
        self::assertStringContainsString('Panier ×3, Cercle ×2', $this->admin->lastBody);

        $this->admin->post('/admin/phase', ['phase' => 'preparation']);
        $this->admin->postJson('/entry/basketball/sync', ['entries' => [$this->entry(['bib' => 1, 'variant' => $variant, 'measured' => 10])]], $csrf);
        self::assertSame('closed', self::dig(json_decode($this->admin->lastBody, true, 512, JSON_THROW_ON_ERROR), 'results', 0, 'status'));
    }

    public function testOfflinePageKeepsNothingAboutTheAccount(): void
    {
        self::assertSame(200, $this->admin->get('/offline')->getStatusCode());
        self::assertStringContainsString('Hors ligne', $this->admin->lastBody);
        self::assertStringNotContainsString('Déconnexion', $this->admin->lastBody);
        self::assertStringNotContainsString('Big Boss', $this->admin->lastBody);
        self::assertStringContainsString('const OFFLINE_PAGE = `${BASE}offline`', (string) file_get_contents(\dirname(__DIR__, 2) . '/public/sw.js'));
    }

    public function testFollowUpPagesRefreshThemselves(): void
    {
        foreach (['/checks', '/results', '/missing', '/dashboard', '/entry/hockey/tracking'] as $page) {
            $this->admin->get($page);
            self::assertStringContainsString('<div data-live>', $this->admin->lastBody, $page);
        }
    }

    public function testSyncNeedsTheSecurityToken(): void
    {
        $response = $this->admin->postJson('/entry/hockey/sync', ['entries' => []], 'wrong');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testOfflineFilesAreServed(): void
    {
        $root = \dirname(__DIR__, 2) . '/public';
        self::assertFileExists($root . '/sw.js');
        $manifest = json_decode((string) file_get_contents($root . '/manifest.webmanifest'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('standalone', self::dig($manifest, 'display'));
        foreach ([0, 1] as $i) {
            self::assertFileExists($root . '/' . self::digString($manifest, 'icons', $i, 'src'));
        }
        // Every file the service worker keeps must exist.
        preg_match_all("/'(assets\\/[^']+|manifest\\.webmanifest)'/", (string) file_get_contents($root . '/sw.js'), $files);
        self::assertNotEmpty($files[1]);
        foreach ($files[1] as $file) {
            self::assertFileExists($root . '/' . $file);
        }
    }

    private function pack(string $workshop): mixed
    {
        $response = $this->admin->get('/entry/' . $workshop . '/pack');
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        return json_decode($this->admin->lastBody, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function digString(mixed $data, string|int ...$keys): string
    {
        $value = self::dig($data, ...$keys);
        self::assertIsString($value);

        return $value;
    }

    /**
     * A value deep in decoded JSON.
     */
    private static function dig(mixed $data, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function entry(array $fields): array
    {
        return ['id' => bin2hex(random_bytes(16)), 'modifiers' => [], 'details' => [], 'warnings' => [], 'at' => time() * 1000, ...$fields];
    }

    private function add(string $lastName, string $firstName, string $level, string $birthDate): void
    {
        $response = $this->admin->post('/registrations/new', [
            'last_name' => $lastName, 'first_name' => $firstName, 'birth_date' => $birthDate, 'gender' => 'girl',
            'school' => 'École des Tilleuls', 'level' => $level, 'guardian_name' => 'Anne ' . $lastName,
            'guardian_phone' => '0611223344', 'guardian_email' => 'parent@example.org', 'volunteer' => '0',
        ]);
        self::assertSame(303, $response->getStatusCode(), $this->admin->lastBody);
    }
}
