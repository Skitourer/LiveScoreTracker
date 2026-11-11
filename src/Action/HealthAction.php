<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Infrastructure\BuildInfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Machine-readable health check, used after each deployment.
 */
final class HealthAction
{
    public function __construct(
        private readonly SystemStatus $status,
        private readonly BuildInfo $build,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = $this->status->check();
        $healthy = $status['database'] && $status['pendingMigrations'] === 0;

        return JsonResponder::write($response, [
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => $this->build->version,
            'revision' => $this->build->revision,
            'database' => $status['database'],
            'pendingMigrations' => $status['pendingMigrations'],
        ], $healthy ? 200 : 503);
    }
}
