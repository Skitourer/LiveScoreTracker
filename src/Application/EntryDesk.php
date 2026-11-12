<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Config\Workshop;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * What the entry screens need to know about a workshop and a bib (spec § 8.2),
 * including the blocking checks of spec § 10.1.
 */
final class EntryDesk
{
    public const UNKNOWN_BIB = 'entry.error.unknown_bib';
    public const NOT_REGISTERED = 'entry.error.not_registered';
    public const OTHER_GROUP = 'entry.error.other_group';

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
    ) {}

    public function workshop(string $id): ?Workshop
    {
        foreach ($this->config->workshops as $workshop) {
            if ($workshop->id === $id) {
                return $workshop;
            }
        }

        return null;
    }

    /**
     * The workshop's variant for a child's level, or null when the workshop is not for that level.
     */
    public function variantFor(Workshop $workshop, string $level): ?Variant
    {
        $group = $this->config->groupOf($level);
        foreach ($workshop->variants as $variant) {
            if ($variant->group === $group) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * The child and the event for a bib typed at a workshop, or the blocking error.
     *
     * @return array{0: Registration, 1: Variant}|string
     */
    public function resolve(Workshop $workshop, int $bib): array|string
    {
        $child = $this->registrations->findByBib($bib);
        if ($child === null) {
            return self::UNKNOWN_BIB;
        }
        if ($child->status !== RegistrationStatus::Registered) {
            return self::NOT_REGISTERED;
        }
        $variant = $this->variantFor($workshop, $child->child->level);

        return $variant === null ? self::OTHER_GROUP : [$child, $variant];
    }

    public function progress(Registration $child, Variant $variant): AttemptProgress
    {
        return new AttemptProgress($variant, $this->attempts->current($child->id, $variant->id()));
    }

    /**
     * Time events: entered by race of 1 or 2 children, with the phone's timer or an external one (spec § 8.4).
     */
    public function isTimed(Workshop $workshop): bool
    {
        foreach ($workshop->variants as $variant) {
            if ($variant->measure === Measure::Time) {
                return true;
            }
        }

        return false;
    }

    /**
     * Children per race (1 or 2): the most any variant of the workshop allows.
     */
    public function bibsPerRace(Workshop $workshop): int
    {
        $bibs = 1;
        foreach ($workshop->variants as $variant) {
            if ($variant->measure === Measure::Time) {
                $bibs = max($bibs, $variant->bibsPerTimer);
            }
        }

        return min($bibs, 2);
    }

    /**
     * Mode offered first: the built-in timer if the configuration asks for it, else the external timer.
     */
    public function defaultsToTimer(Workshop $workshop): bool
    {
        foreach ($workshop->variants as $variant) {
            if ($variant->measure === Measure::Time && $variant->builtInTimer) {
                return true;
            }
        }

        return false;
    }

    /**
     * Levels the workshop is for, in display order.
     *
     * @return list<string>
     */
    public function levels(Workshop $workshop): array
    {
        return array_values(array_filter($this->config->levels, fn(string $level): bool => $this->variantFor($workshop, $level) !== null));
    }
}
