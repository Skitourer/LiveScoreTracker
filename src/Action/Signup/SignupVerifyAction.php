<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Signup;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\OnlineRegistration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Verification link of the email (spec § 5.3): registered with a bib, or on
 * the waiting list when the quota is reached; expired after 48 h.
 */
final class SignupVerifyAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly SignupLanguage $language,
        private readonly OnlineRegistration $online,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->online->verify($args['token'] ?? '');
        $registration = $result['registration'];
        $locale = $registration?->locale !== null ? $this->language->use($registration->locale) : $this->language->choose($request);
        if (\in_array($result['outcome'], [OnlineRegistration::VERIFIED, OnlineRegistration::WAITLISTED], true) && $registration !== null) {
            $this->responder->audit('registration.signup_verified', ['bib' => $registration->bib, 'status' => $registration->status->value]);
        }

        return $this->responder->render($response, 'signup/result.html.twig', [
            ...SignupLanguage::view($locale),
            'outcome' => $result['outcome'],
            'first_name' => $registration?->child->firstName ?? '',
            'bib' => $registration?->bib,
            'status' => $registration?->status->value,
        ], $result['outcome'] === OnlineRegistration::INVALID ? 404 : 200);
    }
}
