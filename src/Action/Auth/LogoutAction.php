<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LogoutAction
{
    public function __construct(private readonly Responder $responder) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->responder->context->user !== null) {
            $this->responder->audit('auth.logout');
        }
        $this->responder->context->session()->destroy();

        return $this->responder->redirect($response, '/login');
    }
}
