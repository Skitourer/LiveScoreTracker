<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Support\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page shown by the phone instead of a browser error when a page needs the
 * network and there is none (spec § 8.8). The service worker keeps a copy;
 * it lists the workshops whose data is on the phone.
 */
final class OfflineAction
{
    public function __construct(private readonly Responder $responder) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->responder->render($response, 'offline.html.twig');
    }
}
