<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Child card (spec § 5.9): details, correction, status changes and history.
 *
 * Correcting is open to admins and organizers; validating a waiting-list
 * child and withdrawing are admin actions (spec § 3.2).
 */
final class RegistrationCardAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
        private readonly ChildValidator $validator,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * @param array{id?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $registration = $this->registrations->find((int) ($args['id'] ?? 0));
        if ($registration === null) {
            throw new HttpNotFoundException($request);
        }
        $context = $this->responder->context;
        $values = RegistrationForm::fromChild($registration->child);
        $errors = [];
        $warnings = [];

        if ($request->getMethod() === 'POST') {
            $op = Responder::field($request, 'op');

            if ($op === 'update') {
                if (!$context->can(Permission::EditRegistration)) {
                    throw new HttpForbiddenException($request);
                }
                $values = RegistrationForm::input($request);
                $birthOptional = $registration->source === RegistrationSource::OnSite || $registration->child->birthDate === null;
                $result = $this->validator->validate($values, birthDateRequired: !$birthOptional);
                $errors = $result->errors;
                $warnings = $result->warnings;
                if ($result->data !== null) {
                    $duplicate = $this->registrations->findDuplicate($result->data, $registration->id);
                    if ($duplicate !== null) {
                        $errors['registration.duplicate'] = ['bib' => $duplicate->bib ?? '–', 'name' => $duplicate->fullName()];
                    } elseif ($warnings === [] || Responder::field($request, 'confirm_warnings') === '1') {
                        $changes = $this->registrations->update($registration->id, $result->data, $context->user);
                        if ($changes !== []) {
                            $this->responder->audit('registration.updated', ['bib' => $registration->bib, 'fields' => implode(', ', array_keys($changes))]);
                            $this->responder->flash('success', 'registration.updated');
                        }

                        return $this->responder->redirect($response, '/registrations/' . $registration->id);
                    }
                }
            } elseif ($op === 'status') {
                $target = RegistrationStatus::tryFrom(Responder::field($request, 'status'));
                $permission = $target === RegistrationStatus::Withdrawn ? Permission::Unregister : Permission::ManageWaitingList;
                if (!$context->can($permission)) {
                    throw new HttpForbiddenException($request);
                }
                if ($target !== null && \in_array($target, $registration->status->adminTransitions(), true)) {
                    $this->registrations->changeStatus($registration->id, $target, $context->user, $this->config->registration->firstBib);
                    $this->responder->audit('registration.status_changed', [
                        'bib' => $registration->bib,
                        'from' => $registration->status->value,
                        'to' => $target->value,
                    ]);
                    $this->responder->flash('success', 'registration.status_changed_to.' . $target->value);
                }

                return $this->responder->redirect($response, '/registrations/' . $registration->id);
            }
        }

        return $this->responder->render($response, 'registrations/card.html.twig', [
            'registration' => $registration,
            'values' => $values,
            'errors' => $errors,
            'warnings' => $errors === [] ? $warnings : [],
            'transitions' => $registration->status->adminTransitions(),
            'history' => $this->registrations->history($registration->id),
            'config' => $this->config,
        ], $errors === [] && $warnings === [] ? 200 : 422);
    }
}
