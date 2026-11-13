<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Signup;

use LiveScoreTracker\Action\Support\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Check your mailbox" page after the public form, with "register another child".
 */
final class SignupSentAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SignupLanguage $language,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $locale = $this->language->choose($request);
        $sent = $this->responder->context->session()->get('signup_sent');
        if (!\is_array($sent)) {
            return $this->responder->redirect($response, '/inscription');
        }

        return $this->responder->render($response, 'signup/sent.html.twig', [
            ...SignupLanguage::view($locale),
            'email' => \is_string($sent['email'] ?? null) ? $sent['email'] : '',
            'first_name' => \is_string($sent['first_name'] ?? null) ? $sent['first_name'] : '',
            'failed' => ($sent['failed'] ?? false) === true,
            'hours' => \LiveScoreTracker\Application\OnlineRegistration::LINK_HOURS,
            'page' => '/inscription/envoye',
        ]);
    }
}
