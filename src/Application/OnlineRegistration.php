<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * Registration by the families themselves (spec § 5.3 to § 5.5):
 * public form → verification email (link valid 48 h) → registered with a bib
 * while the quota is not reached, otherwise waiting list; withdrawal through
 * the link of the confirmation email while the competition is being prepared.
 */
final class OnlineRegistration
{
    public const LINK_HOURS = 48;

    public const VERIFIED = 'verified';
    public const WAITLISTED = 'waitlisted';
    public const ALREADY = 'already';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly RegistrationMailer $mailer,
        private readonly CompetitionConfig $config,
        private readonly AppState $appState,
        private readonly Clock $clock,
    ) {}

    /**
     * Stores the registration and sends the verification email.
     *
     * @return array{registration: Registration, email_sent: bool}
     */
    public function submit(ChildData $child, string $locale): array
    {
        $token = bin2hex(random_bytes(24));
        $id = $this->registrations->createPending($child, $locale, hash('sha256', $token), $this->clock->stampIn('+' . self::LINK_HOURS . ' hours'));
        $registration = $this->registrations->find($id) ?? throw new \LogicException('Registration not stored.');

        return ['registration' => $registration, 'email_sent' => $this->mailer->sendVerification($registration, $token)];
    }

    /**
     * Same child already registered, waiting for verification or on the waiting list (spec § 5.1).
     */
    public function isDuplicate(ChildData $child): bool
    {
        return $this->registrations->findDuplicate($child) !== null;
    }

    /**
     * The family clicked the verification link.
     *
     * @return array{outcome: string, registration: Registration|null}
     */
    public function verify(string $token): array
    {
        $this->expireStale();
        $registration = $this->registrations->findByVerifyToken(hash('sha256', $token));
        if ($registration === null) {
            return ['outcome' => self::INVALID, 'registration' => null];
        }
        if ($registration->status === RegistrationStatus::Expired) {
            return ['outcome' => self::EXPIRED, 'registration' => $registration];
        }
        if ($registration->status !== RegistrationStatus::PendingVerification) {
            return ['outcome' => self::ALREADY, 'registration' => $registration];
        }

        $registered = $this->registrations->countByStatus()[RegistrationStatus::Registered->value] ?? 0;
        $target = $registered < $this->config->registration->quota ? RegistrationStatus::Registered : RegistrationStatus::Waitlisted;
        $this->registrations->changeStatus($registration->id, $target, null, $this->config->registration->firstBib);
        $updated = $this->registrations->find($registration->id) ?? $registration;

        if ($target === RegistrationStatus::Registered) {
            $this->mailer->sendConfirmation($updated);

            return ['outcome' => self::VERIFIED, 'registration' => $updated];
        }
        $this->mailer->sendWaitlisted($updated);
        $this->mailer->notifyOrganizers('waitlisted', $updated);

        return ['outcome' => self::WAITLISTED, 'registration' => $updated];
    }

    /**
     * Registration the withdrawal link points to, if it may still be withdrawn by the family.
     */
    public function withdrawable(string $token): ?Registration
    {
        $registration = $this->registrations->findByWithdrawToken(hash('sha256', $token));

        return $registration !== null && \in_array($registration->status, [RegistrationStatus::Registered, RegistrationStatus::Waitlisted], true)
            ? $registration
            : null;
    }

    /**
     * Only while the competition is being prepared; afterwards the family asks the organization.
     */
    public function withdrawalOpen(): bool
    {
        return $this->appState->phase() === Phase::Preparation;
    }

    public function withdraw(Registration $registration): void
    {
        $this->registrations->changeStatus($registration->id, RegistrationStatus::Withdrawn, null, $this->config->registration->firstBib);
        $this->registrations->setWithdrawToken($registration->id, null);
        $updated = $this->registrations->find($registration->id) ?? $registration;
        $this->mailer->sendWithdrawn($updated);
        $this->mailer->notifyOrganizers('withdrawn', $registration);
    }

    /**
     * Registrations not verified within 48 h expire (checked when needed, no scheduled task).
     */
    public function expireStale(): void
    {
        foreach ($this->registrations->stalePendingIds($this->clock->stamp()) as $id) {
            $this->registrations->changeStatus($id, RegistrationStatus::Expired, null, $this->config->registration->firstBib);
        }
    }
}
