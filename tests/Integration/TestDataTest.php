<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Test sites: the admin can replace the registrations by 100 fictitious
 * children ready for the competition (95 with a bib, 5 on the waiting list).
 */
final class TestDataTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-testdata-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dataDir));
    }

    public function testResetCreatesChildrenReadyForTheCompetition(): void
    {
        $admin = WebClient::migrated($this->dataDir);
        AdminSession::start($admin);
        $admin->get('/admin/phase');
        self::assertStringContainsString('/admin/test-data', $admin->lastBody);

        // A wrong confirmation word changes nothing.
        $admin->get('/admin/test-data');
        $admin->post('/admin/test-data', ['confirmation' => 'oui']);
        self::assertStringContainsString('Tapez EFFACER', $admin->get('/admin/test-data')->getBody()->__toString());

        $admin->post('/admin/test-data', ['confirmation' => 'effacer']);
        self::assertStringContainsString('95 enfants avec un dossard dont 90 présents, 5 en liste', $admin->get('/admin/test-data')->getBody()->__toString());
        $admin->get('/registrations');
        self::assertSame(5, substr_count($admin->lastBody, 'liste d&#039;attente</span>'));

        // Running it again starts from scratch: bibs 1 to 95 again.
        $admin->post('/admin/test-data', ['confirmation' => 'EFFACER']);
        $admin->get('/registrations?q=95');
        self::assertMatchesRegularExpression('#<td class="num">95</td>#', $admin->lastBody);
        $admin->get('/registrations?q=96');
        self::assertDoesNotMatchRegularExpression('#<td class="num">96</td>#', $admin->lastBody);
    }

    public function testNotAvailableInProduction(): void
    {
        $admin = WebClient::migrated($this->dataDir, 'production');
        AdminSession::start($admin);
        self::assertSame(404, $admin->get('/admin/test-data')->getStatusCode());
    }
}
