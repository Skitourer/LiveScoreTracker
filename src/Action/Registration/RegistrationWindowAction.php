<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\RegistrationWindow;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin forcing the online registration open or closed whatever the dates (spec § 5.2).
 */
final class RegistrationWindowAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationWindow $window,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $state = Responder::field($request, 'state');
        $this->window->force($state);
        $forced = $this->window->forced();
        $this->responder->audit('registration.window_forced', ['state' => $forced === '' ? 'auto' : $forced]);
        $this->responder->flash('success', 'registration.window.changed.' . ($forced === '' ? 'auto' : $forced));

        return $this->responder->redirect($response, '/registrations');
    }
}
