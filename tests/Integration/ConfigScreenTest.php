<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Step 12a: configuration screen (spec § 12.3).
 */
final class ConfigScreenTest extends TestCase
{
    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-config-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testFirstSaveCopiesTheExampleAndKeepsHistory(): void
    {
        $page = $this->admin->get('/admin/config');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Multisport Games 2027', $this->admin->lastBody);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');

        $form = $this->editionForm(['name' => 'Jeux 2027', 'venue' => 'Stade fictif']);
        $saved = $this->admin->post('/admin/config/edition', $form);
        self::assertSame('/admin/config/edition', $saved->getHeaderLine('Location'));
        self::assertFileExists($this->dataDir . '/competition.toml');
        self::assertStringContainsString('Jeux 2027', (string) file_get_contents($this->dataDir . '/competition.toml'));

        // The second save archives the first one.
        $this->admin->post('/admin/config/edition', $this->editionForm(['name' => 'Jeux 2028']));
        $this->admin->get('/admin/config/file');
        self::assertStringContainsString('Revenir à cette version', $this->admin->lastBody);

        // The change is in the audit log, with the old and new values.
        $this->admin->get('/admin/audit');
        self::assertStringContainsString('Jeux 2027', $this->admin->lastBody);
    }

    public function testInvalidValuesAreRefusedWithTheirLocation(): void
    {
        $response = $this->admin->post('/admin/config/scoring', [
            'max_score' => '1000', 'floor' => '2000', 'foul_score' => '1', 'dnf_score' => '0', 'tie_breakers' => ['youngest'],
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('must be lower than max_score', $this->admin->lastBody);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');
    }

    public function testAddsAWorkshopWithItsOwnName(): void
    {
        $saved = $this->admin->post('/admin/config/workshops/new', [
            'id' => 'relay', 'name' => 'Relais fictif', 'help' => '',
            'variants' => [
                ['group' => 'junior', 'label' => '', 'measure' => 'time', 'better' => 'lower', 'attempts' => '1', 'foul_mode' => 'strict', 'time_format' => 'ss.cc', 'bibs_per_timer' => '2', 'range_min' => '5', 'range_max' => '30',
                    'modifiers' => [['id' => 'baton_dropped', 'name' => 'Témoin tombé', 'value' => '2', 'repeatable' => '1']]],
            ],
        ]);
        self::assertSame('/admin/config/workshops', $saved->getHeaderLine('Location'));
        $this->admin->get('/admin/config/workshops');
        self::assertStringContainsString('Relais fictif', $this->admin->lastBody);
        $file = (string) file_get_contents($this->dataDir . '/competition.toml');
        self::assertStringContainsString('baton_dropped', $file);

        // Duplicating gives a new identifier; deleting is allowed without results.
        $this->admin->get('/admin/config/workshops');
        $index = substr_count($file, '[[workshops]]') - 1;
        $this->admin->post('/admin/config/workshops', ['index' => (string) $index, 'op' => 'duplicate']);
        self::assertStringContainsString("id = 'relay_2'", (string) file_get_contents($this->dataDir . '/competition.toml'));
        $this->admin->post('/admin/config/workshops', ['index' => (string) ($index + 1), 'op' => 'delete']);
        self::assertStringNotContainsString('relay_2', (string) file_get_contents($this->dataDir . '/competition.toml'));
    }

    public function testImportShowsTheChangesBeforeApplying(): void
    {
        $toml = str_replace('Multisport Games 2027', 'Imported Games', (string) file_get_contents(\dirname(__DIR__, 2) . '/config.example/competition.toml'));
        $file = $this->dataDir . '/import.toml';
        file_put_contents($file, $toml);
        $this->admin->upload('/admin/config/file', 'file', $file, 'competition.toml', ['op' => 'import']);
        self::assertStringContainsString('Imported Games', $this->admin->lastBody);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');

        $this->admin->post('/admin/config/file', ['op' => 'confirm', 'source' => 'import', 'toml' => $toml]);
        self::assertStringContainsString('Imported Games', (string) file_get_contents($this->dataDir . '/competition.toml'));
        // The comments of an imported file are kept.
        self::assertStringContainsString('# ---------- Workshops ----------', (string) file_get_contents($this->dataDir . '/competition.toml'));
    }

    public function testFrozenTabsWhileTheCompetitionIsRunning(): void
    {
        $this->admin->post('/admin/phase', ['phase' => 'running']);
        $this->admin->get('/admin/config/workshops');
        self::assertStringContainsString('Compétition en cours', $this->admin->lastBody);
        $this->admin->post('/admin/config/workshops', ['index' => '0', 'op' => 'delete']);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');

        // The edition stays editable.
        $this->admin->post('/admin/config/edition', $this->editionForm(['venue' => 'Autre stade']));
        self::assertStringContainsString('Autre stade', (string) file_get_contents($this->dataDir . '/competition.toml'));
    }

    public function testOrganizersHaveNoAccess(): void
    {
        $organizer = AdminSession::organizer($this->admin);
        self::assertSame(403, $organizer->get('/admin/config')->getStatusCode());
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function editionForm(array $changes): array
    {
        return $changes + [
            'name' => 'Multisport Games 2027', 'organizer' => 'Springfield School Sports Club', 'date' => '2027-06-06',
            'start' => '09:00', 'end' => '12:00', 'venue' => 'Springfield Municipal Stadium',
            'contact_email' => 'contact@example.org', 'default_locale' => 'fr',
        ];
    }
}
