<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Infrastructure\BuildInfo;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * Home page. For now it only confirms that the foundation is up and running.
 */
final class HomeAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SystemStatus $status,
        private readonly BuildInfo $build,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response, 'home.html.twig', [
            'build' => $this->build,
            'environment' => $this->settings->environment,
            'status' => $this->status->check(),
        ]);
    }
}
