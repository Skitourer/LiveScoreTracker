<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\RegistrationMailer;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Manual registration by an admin or organizer, including on the day (spec § 5.8):
 * no quota limit; during the competition the birth date is optional.
 * The child is registered at once and gets the next bib.
 */
final class RegistrationNewAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
        private readonly ChildValidator $validator,
        private readonly CompetitionConfig $config,
        private readonly RegistrationMailer $mailer,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $onSite = $this->responder->context->phase === Phase::Running;
        $input = array_fill_keys(ChildValidator::FIELDS, '');
        $sendEmail = true;
        $errors = [];
        $warnings = [];

        if ($request->getMethod() === 'POST') {
            $input = RegistrationForm::input($request);
            $sendEmail = Responder::field($request, 'send_email') === '1';
            $result = $this->validator->validate($input, birthDateRequired: !$onSite);
            $errors = $result->errors;
            $warnings = $result->warnings;

            if ($result->data !== null) {
                $duplicate = $this->registrations->findDuplicate($result->data);
                if ($duplicate !== null) {
                    $errors['registration.duplicate'] = ['bib' => $duplicate->bib ?? '–', 'name' => $duplicate->fullName()];
                } elseif ($warnings === [] || Responder::field($request, 'confirm_warnings') === '1') {
                    $id = $this->registrations->create(
                        $result->data,
                        RegistrationStatus::Registered,
                        $onSite ? RegistrationSource::OnSite : RegistrationSource::Manual,
                        $this->responder->context->user,
                        $this->config->registration->firstBib,
                    );
                    $created = $this->registrations->find($id);
                    $bib = $created === null ? null : $created->bib;
                    $this->responder->audit('registration.created', ['bib' => $bib, 'on_site' => $onSite]);
                    $this->responder->flash('success', 'registration.created', ['bib' => $bib ?? '–', 'name' => $result->data->firstName . ' ' . $result->data->lastName]);
                    if ($created !== null && Responder::field($request, 'send_email') === '1') {
                        ConfirmationEmail::send($this->mailer, $this->responder, $created);
                    }
                    if ($this->registeredCount() > $this->config->registration->quota) {
                        $this->responder->flash('warning', 'registration.over_quota', ['quota' => $this->config->registration->quota]);
                    }

                    return $this->responder->redirect($response, Responder::field($request, 'another') === '1' ? '/registrations/new' : '/registrations/' . $id);
                }
            }
        }

        return $this->responder->render($response, 'registrations/new.html.twig', [
            'values' => $input,
            'errors' => $errors,
            'warnings' => $errors === [] ? $warnings : [],
            'onSite' => $onSite,
            'sendEmail' => $sendEmail,
            'config' => $this->config,
        ], $errors === [] && $warnings === [] ? 200 : 422);
    }

    private function registeredCount(): int
    {
        return $this->registrations->countByStatus()[RegistrationStatus::Registered->value] ?? 0;
    }
}
