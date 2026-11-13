<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\OnlineRegistration;
use LiveScoreTracker\Application\RegistrationWindow;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Registrations" screen (spec § 5.9): full list with search, filters and counters.
 * Full names and contacts are shown: admins and organizers only.
 */
final class RegistrationsAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
        private readonly CompetitionConfig $config,
        private readonly OnlineRegistration $online,
        private readonly RegistrationWindow $window,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->online->expireStale();
        $status = RegistrationStatus::tryFrom(Responder::query($request, 'status'));
        $filters = [
            'q' => Responder::query($request, 'q'),
            'school' => Responder::query($request, 'school'),
            'level' => Responder::query($request, 'level'),
            'gender' => Responder::query($request, 'gender'),
            'volunteer' => Responder::query($request, 'volunteer') === '1',
        ];
        // By default, hide withdrawn and expired registrations.
        $statuses = $status !== null
            ? [$status]
            : (Responder::query($request, 'status') === 'all' ? [] : [RegistrationStatus::Registered, RegistrationStatus::Waitlisted, RegistrationStatus::PendingVerification]);

        return $this->responder->render($response, 'registrations/list.html.twig', [
            'registrations' => $this->registrations->search([...$filters, 'statuses' => $statuses]),
            'counts' => $this->registrations->countByStatus(),
            'quota' => $this->config->registration->quota,
            'filters' => [...$filters, 'status' => Responder::query($request, 'status')],
            'statuses' => RegistrationStatus::cases(),
            'config' => $this->config,
            'window' => $this->window->status($this->config),
        ]);
    }
}
