<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use DateTimeImmutable;
use DateTimeZone;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Settings;

/**
 * Where online registration stands (spec § 5.2), for the public home page:
 * not scheduled, not open yet, open, full (new registrations go to the
 * waiting list) or closed, with the places left.
 *
 * The configured dates are wall-clock times of the installation's time zone.
 * An admin may force the form open or closed whatever the dates (stored in
 * `app_meta`); the quota still applies (waiting list when full).
 */
final class RegistrationWindow
{
    public const UNSCHEDULED = 'unscheduled';
    public const UPCOMING = 'upcoming';
    public const OPEN = 'open';
    public const FULL = 'full';
    public const CLOSED = 'closed';

    /** `app_meta` key of the state forced by an admin: '', 'open' or 'closed'. */
    public const OVERRIDE = 'registration_override';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly Clock $clock,
        private readonly Settings $settings,
        private readonly AppState $appState,
    ) {}

    /**
     * @return array{state: string, opens_at: DateTimeImmutable|null, closes_at: DateTimeImmutable|null, quota: int, remaining: int, forced: string}
     */
    public function status(CompetitionConfig $config): array
    {
        $registration = $config->registration;
        $opensAt = $this->local($registration->opensAt);
        $closesAt = $this->local($registration->closesAt);
        $registered = $this->registrations->countByStatus()[RegistrationStatus::Registered->value] ?? 0;
        $remaining = max(0, $registration->quota - $registered);
        $now = $this->clock->now();

        $forced = $this->forced();
        $state = match (true) {
            $forced === self::CLOSED => self::CLOSED,
            $forced === self::OPEN => $remaining === 0 ? self::FULL : self::OPEN,
            $opensAt === null => self::UNSCHEDULED,
            $now < $opensAt => self::UPCOMING,
            $closesAt !== null && $now >= $closesAt => self::CLOSED,
            $remaining === 0 => self::FULL,
            default => self::OPEN,
        };

        return ['state' => $state, 'opens_at' => $opensAt, 'closes_at' => $closesAt, 'quota' => $registration->quota, 'remaining' => $remaining, 'forced' => $forced];
    }

    /**
     * Whether the public form takes registrations now: open or full (waiting
     * list), and only while the competition is being prepared.
     */
    public function acceptsRegistrations(CompetitionConfig $config): bool
    {
        return $this->appState->phase() === Phase::Preparation
            && \in_array($this->status($config)['state'], [self::OPEN, self::FULL], true);
    }

    /**
     * State forced by an admin: '' (follow the dates), 'open' or 'closed'.
     */
    public function forced(): string
    {
        $value = $this->appState->get(self::OVERRIDE) ?? '';

        return \in_array($value, [self::OPEN, self::CLOSED], true) ? $value : '';
    }

    public function force(string $state): void
    {
        $this->appState->set(self::OVERRIDE, \in_array($state, [self::OPEN, self::CLOSED], true) ? $state : '');
    }

    private function local(?DateTimeImmutable $wallClock): ?DateTimeImmutable
    {
        return $wallClock === null ? null : new DateTimeImmutable($wallClock->format('Y-m-d H:i:s'), new DateTimeZone($this->settings->timezone));
    }
}
