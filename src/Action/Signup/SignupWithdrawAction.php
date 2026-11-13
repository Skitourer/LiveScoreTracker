<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Signup;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\OnlineRegistration;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Withdrawal link of the confirmation email (spec § 5.5), after a confirmation
 * step; only while the competition is being prepared. The organization is notified.
 */
final class SignupWithdrawAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SignupLanguage $language,
        private readonly OnlineRegistration $online,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';
        $registration = $this->online->withdrawable($token);
        $locale = $registration?->locale !== null ? $this->language->use($registration->locale) : $this->language->choose($request);
        $view = [
            ...SignupLanguage::view($locale),
            'token' => $token,
            'child' => $registration === null ? '' : $registration->child->firstName . ' ' . $registration->child->lastName,
            'first_name' => $registration?->child->firstName ?? '',
            'contact' => $this->config->edition->contactEmail ?? $this->config->edition->organizer,
        ];

        if ($registration === null) {
            return $this->responder->render($response, 'signup/withdraw.html.twig', [...$view, 'state' => 'invalid'], 404);
        }
        if (!$this->online->withdrawalOpen()) {
            return $this->responder->render($response, 'signup/withdraw.html.twig', [...$view, 'state' => 'closed']);
        }
        if ($request->getMethod() === 'POST') {
            $this->online->withdraw($registration);
            $this->responder->audit('registration.withdrawn_online', ['bib' => $registration->bib]);

            return $this->responder->render($response, 'signup/withdraw.html.twig', [...$view, 'state' => 'done']);
        }

        return $this->responder->render($response, 'signup/withdraw.html.twig', [...$view, 'state' => 'confirm']);
    }
}
