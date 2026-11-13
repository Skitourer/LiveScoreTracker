<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Signup;

use LiveScoreTracker\Action\Registration\RegistrationForm;
use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\OnlineRegistration;
use LiveScoreTracker\Application\RegistrationWindow;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\Persistence\Throttle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public registration form (spec § 5.1), in the family's language.
 *
 * Protection against robots without third-party service: a hidden trap field,
 * a minimal filling time and at most 10 registrations per hour from one IP
 * address. After a registration, "register another child" keeps the adult's
 * details (brothers and sisters).
 */
final class SignupAction
{
    public const MIN_SECONDS = 5;
    public const MAX_PER_HOUR = 10;

    private const GUARDIAN_FIELDS = ['guardian_name', 'guardian_phone', 'guardian_email', 'school', 'volunteer'];

    public function __construct(
        private readonly Responder $responder,
        private readonly SignupLanguage $language,
        private readonly RegistrationWindow $window,
        private readonly OnlineRegistration $online,
        private readonly ChildValidator $validator,
        private readonly CompetitionConfig $config,
        private readonly Throttle $throttle,
        private readonly Clock $clock,
        private readonly HelpPages $pages,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $locale = $this->language->choose($request);
        $session = $this->responder->context->session();
        $values = array_fill_keys(ChildValidator::FIELDS, '');
        $errors = [];
        $warnings = [];
        $status = 200;
        $this->online->expireStale();
        $open = $this->window->acceptsRegistrations($this->config);

        if ($request->getMethod() === 'POST' && $open) {
            $values = RegistrationForm::input($request);
            $started = $session->get('signup_started');
            $throttleKey = 'signup:' . ($this->responder->context->ip ?? '-') . ':' . $this->clock->now()->format('YmdH');

            if (Responder::field($request, 'website') !== '') {
                // Robot: pretend it worked, store nothing.
                $session->set('signup_sent', ['email' => $values['guardian_email'], 'first_name' => $values['first_name']]);

                return $this->responder->redirect($response, '/inscription/envoye');
            }
            if ($this->throttle->secondsBlocked($throttleKey) > 0) {
                $errors['signup.too_many'] = [];
                $status = 429;
            } elseif (!\is_int($started) || $this->clock->now()->getTimestamp() - $started < self::MIN_SECONDS) {
                $errors['signup.too_fast'] = [];
            } else {
                $result = $this->validator->validate($values);
                $errors = $result->errors;
                $warnings = $result->warnings;
                foreach (['responsibility', 'rules'] as $box) {
                    if (Responder::field($request, $box) !== '1') {
                        $errors['signup.required_' . $box] = [];
                    }
                }
                if ($errors === [] && $result->data !== null) {
                    if ($this->online->isDuplicate($result->data)) {
                        $errors['signup.duplicate'] = ['contact' => $this->config->edition->contactEmail ?? $this->config->edition->organizer];
                    } elseif ($warnings === [] || Responder::field($request, 'confirm_warnings') === '1') {
                        $this->throttle->recordFailure($throttleKey, self::MAX_PER_HOUR);
                        $outcome = $this->online->submit($result->data, $locale);
                        $this->responder->audit('registration.signup_submitted', ['id' => $outcome['registration']->id, 'email_sent' => $outcome['email_sent']]);
                        $session->set('signup_guardian', array_intersect_key($values, array_flip(self::GUARDIAN_FIELDS)));
                        $session->set('signup_sent', ['email' => $result->data->guardianEmail, 'first_name' => $result->data->firstName, 'failed' => !$outcome['email_sent']]);

                        return $this->responder->redirect($response, '/inscription/envoye');
                    }
                }
            }
            if ($errors !== []) {
                $status = max($status, 422);
            }
        } elseif (Responder::query($request, 'another') === '1') {
            $kept = $session->get('signup_guardian');
            if (\is_array($kept)) {
                foreach (self::GUARDIAN_FIELDS as $field) {
                    $values[$field] = \is_string($kept[$field] ?? null) ? $kept[$field] : '';
                }
            }
        }
        $session->set('signup_started', $this->clock->now()->getTimestamp());

        return $this->responder->render($response, 'signup/form.html.twig', [
            ...SignupLanguage::view($locale),
            'open' => $open,
            'window' => $this->window->status($this->config),
            'values' => $values,
            'errors' => $errors,
            'warnings' => $errors === [] ? $warnings : [],
            'config' => $this->config,
            'edition' => $this->config->edition,
            'has_rules' => $this->pages->page('rules', $locale, $this->config->edition->defaultLocale) !== null,
            'responsibility' => Responder::field($request, 'responsibility') === '1',
            'rules' => Responder::field($request, 'rules') === '1',
            'page' => '/inscription',
        ], $status);
    }
}
