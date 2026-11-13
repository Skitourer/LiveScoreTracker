<?php

declare(strict_types=1);

namespace LiveScoreTracker\Tests\Integration;

use LiveScoreTracker\Infrastructure\Settings;
use LiveScoreTracker\Kernel;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Runs the full Slim application against a temporary SQLite database.
 */
final class HttpTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lst-data-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dataDir)) {
            exec('rm -rf ' . escapeshellarg($this->dataDir));
        }
    }

    public function testStatusPageShowsPendingMigrationsThenUpToDate(): void
    {
        $home = (string) $this->request('GET', '/')->getBody();
        self::assertStringContainsString('Olympiades fictives 2027', $home, 'The public home page works before the first migration.');

        $body = (string) $this->request('GET', '/status')->getBody();
        self::assertStringContainsString('LiveScoreTracker', $body);
        self::assertStringContainsString('migration(s) en attente', $body);
        self::assertStringContainsString('12 épreuves, 10 catégories', $body);
        self::assertStringContainsString('/demo/report', $body);

        $this->request('POST', '/internal/migrate', ['X-Deploy-Token' => 'test-token']);

        $body = (string) $this->request('GET', '/status')->getBody();
        self::assertStringContainsString('à jour', $body);
    }

    public function testHealthIsDegradedUntilMigrated(): void
    {
        $response = $this->request('GET', '/health');
        self::assertSame(503, $response->getStatusCode());

        $migrate = $this->request('POST', '/internal/migrate', ['X-Deploy-Token' => 'test-token']);
        self::assertSame(200, $migrate->getStatusCode());
        self::assertStringContainsString('0001_create_app_meta', (string) $migrate->getBody());

        $response = $this->request('GET', '/health');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"status":"ok"', (string) $response->getBody());
    }

    public function testMigrateRejectsWrongToken(): void
    {
        self::assertSame(403, $this->request('POST', '/internal/migrate', ['X-Deploy-Token' => 'wrong'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/internal/migrate')->getStatusCode());
    }

    public function testMigrateIsDisabledWithoutToken(): void
    {
        $response = $this->request('POST', '/internal/migrate', ['X-Deploy-Token' => ''], deployToken: '');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testScoringReportOnFictitiousData(): void
    {
        $response = $this->request('GET', '/demo/report?seed=5&category=CE2-boy&state=running');
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Garçons CE2', $body);
        self::assertStringContainsString('Sprint 40 m', $body);
        self::assertStringContainsString('tirage n° 5', $body);
    }

    public function testScoringReportIsHiddenInProduction(): void
    {
        self::assertSame(404, $this->request('GET', '/demo/report', environment: 'production')->getStatusCode());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        string $deployToken = 'test-token',
        string $environment = 'test',
    ): ResponseInterface {
        $settings = Settings::fromEnvironment(\dirname(__DIR__, 2), [
            'APP_ENV' => $environment,
            'APP_DEBUG' => 'true',
            'DATA_DIR' => $this->dataDir,
            'DB_DRIVER' => 'sqlite',
            'DEPLOY_TOKEN' => $deployToken,
        ]);

        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return Kernel::createApp($settings)->handle($request);
    }
}
