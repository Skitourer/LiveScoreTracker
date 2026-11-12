<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Results;

use LiveScoreTracker\Application\Progress;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

/**
 * Progress of the competition (spec § 13.1): the public screen for a
 * projector or a TV (no login, no personal data, refreshed every few
 * seconds), and its detailed version by category for admins and organizers
 * (spec § 11.5).
 */
final class ProgressAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Progress $progress,
        private readonly AppState $appState,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $detailed = RouteContext::fromRequest($request)->getRoute()?->getName() === 'progress-detail';

        return $this->twig->render($response, $detailed ? 'results/progress-detail.html.twig' : 'results/progress.html.twig', [
            'progress' => $this->progress->compute(),
            'phase' => $this->appState->phase()->value,
        ]);
    }
}
