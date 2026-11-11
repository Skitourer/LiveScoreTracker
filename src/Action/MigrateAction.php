<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Infrastructure\Database\Migrator;
use LiveScoreTracker\Infrastructure\Settings;
use LiveScoreTracker\Kernel;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Applies pending database migrations; called by the deployment workflow.
 *
 * Shared hosting gives no shell access during deployment, so the workflow calls
 * this endpoint with the `X-Deploy-Token` header. It is disabled (404) when no
 * DEPLOY_TOKEN is configured on the server.
 */
final class MigrateAction
{
    public const TOKEN_HEADER = 'X-Deploy-Token';

    public function __construct(
        private readonly Settings $settings,
        private readonly ContainerInterface $container,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->deployToken === '') {
            return JsonResponder::write($response, ['error' => 'not_found'], 404);
        }

        if (!hash_equals($this->settings->deployToken, $request->getHeaderLine(self::TOKEN_HEADER))) {
            return JsonResponder::write($response, ['error' => 'forbidden'], 403);
        }

        try {
            $applied = Kernel::service($this->container, Migrator::class)->migrate();
        } catch (Throwable $exception) {
            return JsonResponder::write($response, [
                'error' => 'migration_failed',
                'message' => $this->settings->debug ? $exception->getMessage() : 'See server logs.',
            ], 500);
        }

        return JsonResponder::write($response, ['applied' => $applied]);
    }
}
