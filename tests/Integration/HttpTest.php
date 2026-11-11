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

    public function testHomePageShowsPendingMigrationsThenUpToDate(): void
    {
        $body = (string) $this->request('GET', '/')->getBody();
        self::assertStringContainsString('LiveScoreTracker', $body);
        self::assertStringContainsString('migration(s) en attente', $body);

        $this->request('POST', '/internal/migrate', ['X-Deploy-Token' => 'test-token']);

        $body = (string) $this->request('GET', '/')->getBody();
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

    /**
     * @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $headers = [],
        string $deployToken = 'test-token',
    ): ResponseInterface {
        $settings = Settings::fromEnvironment(\dirname(__DIR__, 2), [
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'DATA_DIR' => $this->dataDir,
            'DB_DRIVER' => 'sqlite',
            'DEPLOY_TOKEN' => $deployToken,
        ]);

        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return Kernel::createApp($settings)->handle($request);
    }
}
