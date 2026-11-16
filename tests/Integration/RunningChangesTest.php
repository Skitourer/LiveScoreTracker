<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use Internal\Toml\Toml;
use LiveScoreTracker\Application\ConfigForms;
use PHPUnit\Framework\TestCase;

/**
 * Step 12b: configuration changes while the competition is running (spec § 10.4).
 */
final class RunningChangesTest extends TestCase
{
    private const LONG_JUMP = 6;

    private string $dataDir;
    private WebClient $admin;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-running-' . bin2hex(random_bytes(4));
        $this->admin = WebClient::migrated($this->dataDir);
        AdminSession::start($this->admin);
        $this->admin->post('/admin/test-data', ['confirmation' => 'EFFACER', 'with_results' => '1']);
        $this->admin->post('/admin/phase', ['phase' => 'running']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testMoreAttemptsAreShownThenConfirmedWithAnAlert(): void
    {
        $preview = $this->admin->post('/admin/config/workshops/' . self::LONG_JUMP, $this->longJump(['attempts' => '3']));
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('Enfants concernés', $this->admin->lastBody);
        self::assertStringContainsString('un essai à faire ou à refaire', $this->admin->lastBody);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');

        $this->admin->post('/admin/config/workshops', ['op' => 'confirm_change', 'alert' => '1']);
        self::assertStringContainsString('attempts = 3', (string) file_get_contents($this->dataDir . '/competition.toml'));

        $this->admin->get('/admin/audit');
        self::assertStringContainsString('stations_alerted', $this->admin->lastBody);
        $this->admin->get('/entry/long_jump/pack');
        self::assertMatchesRegularExpression('/"alert":\d+/', $this->admin->lastBody);
    }

    public function testAnEventWithResultsKeepsItsMeasureAndCannotBeRemoved(): void
    {
        $response = $this->admin->post('/admin/config/workshops/' . self::LONG_JUMP, $this->longJump(['measure' => 'score']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('cette épreuve a déjà des résultats', $this->admin->lastBody);
        self::assertStringContainsString('Ateliers › Saut en longueur (senior) › Type de mesure', $this->admin->lastBody);

        $this->admin->post('/admin/config/workshops', ['index' => (string) self::LONG_JUMP, 'op' => 'delete']);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');
    }

    public function testADeactivatedWorkshopLeavesEntryAndRanking(): void
    {
        $this->admin->post('/admin/config/workshops', ['index' => (string) self::LONG_JUMP, 'op' => 'disable']);
        self::assertStringContainsString('Enfants concernés', $this->admin->lastBody);
        $this->admin->post('/admin/config/workshops', ['op' => 'confirm_change']);
        self::assertStringContainsString('disabled = true', (string) file_get_contents($this->dataDir . '/competition.toml'));

        $this->admin->get('/entry');
        self::assertStringNotContainsString('/entry/long_jump', $this->admin->lastBody);
        $this->admin->get('/results?category=CM1-girl');
        self::assertStringContainsString('Épreuve désactivée', $this->admin->lastBody);

        // Reactivating brings the results back.
        $this->admin->post('/admin/config/workshops', ['index' => (string) self::LONG_JUMP, 'op' => 'enable']);
        $this->admin->post('/admin/config/workshops', ['op' => 'confirm_change']);
        self::assertStringNotContainsString('disabled = true', (string) file_get_contents($this->dataDir . '/competition.toml'));
    }

    public function testTheWholeFileStaysReadOnly(): void
    {
        $this->admin->post('/admin/config/file', ['op' => 'edit', 'toml' => 'x = 1']);
        self::assertFileDoesNotExist($this->dataDir . '/competition.toml');
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<mixed>
     */
    private function longJump(array $changes): array
    {
        $data = Toml::parseToArray((string) file_get_contents(\dirname(__DIR__, 2) . '/config.example/competition.toml'));
        self::assertIsArray($data['workshops']);
        self::assertIsArray($data['workshops'][self::LONG_JUMP]);
        $values = ConfigForms::workshopValues($data['workshops'][self::LONG_JUMP]);
        self::assertIsArray($values['variants']);
        self::assertIsArray($values['variants'][0]);
        $values['variants'][0] = $changes + $values['variants'][0];

        return $values;
    }
}
