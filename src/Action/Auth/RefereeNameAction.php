<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Auth;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * After logging in to the shared referee account, the referee types their
 * first name: it is recorded with every action of this phone, and lets the
 * admin see who is connected and disconnect one referee (spec § 19.1).
 */
final class RefereeNameAction
{
    public const MAX_LENGTH = 40;

    public function __construct(private readonly Responder $responder) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        $errors = [];
        $name = $context->refereeName() ?? '';

        if ($request->getMethod() === 'POST') {
            $name = TextCleaner::firstName(Responder::field($request, 'first_name'));
            $length = mb_strlen($name);
            if ($length < 2 || $length > self::MAX_LENGTH) {
                $errors['referee.name_invalid'] = ['max' => self::MAX_LENGTH];
            } else {
                $first = $context->refereeName() === null;
                $context->session()->set(RequestContext::REFEREE_NAME, $name);
                $this->responder->audit($first ? 'auth.login' : 'referee.renamed');

                return $this->responder->redirect($response, $first ? LoginAction::takeNext($context->session()) : '/dashboard');
            }
        }

        return $this->responder->render($response, 'auth/referee-name.html.twig', [
            'name' => $name,
            'errors' => $errors,
            'max' => self::MAX_LENGTH,
        ], $errors === [] ? 200 : 422);
    }
}
