<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Style guide (spec § 20.6): every component on one page, to review a change
 * of theme at a glance. Not available in production.
 */
final class StyleguideAction
{
    public function __construct(
        private readonly Twig $twig,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->isProduction()) {
            throw new HttpNotFoundException($request);
        }

        return $this->twig->render($response, 'demo/styleguide.html.twig');
    }
}
