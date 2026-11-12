<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Results;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\Ceremony;
use LiveScoreTracker\Domain\Security\Phase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * Award ceremony (spec § 13.2), after the closing only, for admins and
 * organizers: the big screen (/ceremony), which follows the state every
 * 2 seconds, and the page that drives it from a phone (/ceremony/control).
 */
final class CeremonyAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly Ceremony $ceremony,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $control = RouteContext::fromRequest($request)->getRoute()?->getName() === 'ceremony-control';
        $finished = $this->responder->context->phase === Phase::Finished;

        if ($control && $finished && $request->getMethod() === 'POST') {
            $state = $this->ceremony->current();
            match (Responder::field($request, 'op')) {
                'reveal' => $this->ceremony->reveal(),
                'hide' => $this->ceremony->hide(),
                'next' => $this->ceremony->goTo($state['index'] + 1),
                'previous' => $this->ceremony->goTo($state['index'] - 1),
                'go' => $this->ceremony->goTo((int) Responder::field($request, 'index')),
                'up' => $this->ceremony->move(Responder::field($request, 'category'), -1),
                'down' => $this->ceremony->move(Responder::field($request, 'category'), 1),
                default => null,
            };

            return $this->responder->redirect($response, '/ceremony/control');
        }

        return $this->responder->render($response, $control ? 'ceremony/control.html.twig' : 'ceremony/screen.html.twig', [
            'finished' => $finished,
            'state' => $finished ? $this->ceremony->current() : null,
        ]);
    }
}
