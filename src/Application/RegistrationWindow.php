<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use DateTimeImmutable;
use DateTimeZone;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Settings;

/**
 * Where online registration stands (spec § 5.2), for the public home page:
 * not scheduled, not open yet, open, full (new registrations go to the
 * waiting list) or closed, with the places left.
 *
 * The configured dates are wall-clock times of the installation's time zone.
 * Forcing the state by hand comes with the online registration form (step 11).
 */
final class RegistrationWindow
{
    public const UNSCHEDULED = 'unscheduled';
    public const UPCOMING = 'upcoming';
    public const OPEN = 'open';
    public const FULL = 'full';
    public const CLOSED = 'closed';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly Clock $clock,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{state: string, opens_at: DateTimeImmutable|null, closes_at: DateTimeImmutable|null, quota: int, remaining: int}
     */
    public function status(CompetitionConfig $config): array
    {
        $registration = $config->registration;
        $opensAt = $this->local($registration->opensAt);
        $closesAt = $this->local($registration->closesAt);
        $registered = $this->registrations->countByStatus()[RegistrationStatus::Registered->value] ?? 0;
        $remaining = max(0, $registration->quota - $registered);
        $now = $this->clock->now();

        $state = match (true) {
            $opensAt === null => self::UNSCHEDULED,
            $now < $opensAt => self::UPCOMING,
            $closesAt !== null && $now >= $closesAt => self::CLOSED,
            $remaining === 0 => self::FULL,
            default => self::OPEN,
        };

        return ['state' => $state, 'opens_at' => $opensAt, 'closes_at' => $closesAt, 'quota' => $registration->quota, 'remaining' => $remaining];
    }

    private function local(?DateTimeImmutable $wallClock): ?DateTimeImmutable
    {
        return $wallClock === null ? null : new DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), new DateTimeZone($this->settings->timezone));
    }
}
