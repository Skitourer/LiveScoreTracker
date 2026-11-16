<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\ConfigError;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Results\ConfigImpact;
use LiveScoreTracker\Domain\Results\StoredAttempt;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * Safeguards for changes made while the competition is running (spec § 10.4):
 * locks on events that have results, children affected by a change, results
 * that follow a child changing level group, and alerts to the stations.
 */
final class ConfigGuard
{
    private const ALERT_KEY = 'station_alert.';

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly RegistrationRepository $registrations,
        private readonly AppState $state,
    ) {}

    /**
     * An event that has results keeps its measure and cannot be removed: it
     * can only be deactivated.
     *
     * @return list<ConfigError>
     */
    public function lockErrors(CompetitionConfig $old, CompetitionConfig $new): array
    {
        $errors = [];
        foreach ($this->attempts->variantsWithResults() as $id) {
            $found = false;
            foreach ($new->workshops as $i => $workshop) {
                foreach ($workshop->variants as $j => $variant) {
                    if ($variant->id() !== $id) {
                        continue;
                    }
                    $found = true;
                    $previous = self::find($old, $id);
                    if ($previous !== null && $previous->measure !== $variant->measure) {
                        $errors[] = new ConfigError("workshops[{$i}].variants[{$j}].measure", 'measure_locked');
                    }
                }
            }
            if (!$found && self::find($old, $id) !== null) {
                $errors[] = new ConfigError('workshops', 'event_has_results', ['id' => $id]);
            }
        }

        return $errors;
    }

    /**
     * Children affected by a configuration change.
     *
     * @return list<array{workshop: string, kind: string, children: list<Registration>}>
     */
    public function configImpact(CompetitionConfig $old, CompetitionConfig $new): array
    {
        $children = array_map(
            static fn(Registration $r): array => ['registration' => $r, 'before' => $r->child->level, 'after' => $r->child->level],
            $this->children(),
        );

        return ConfigImpact::compare($old, $new, $children, $this->allAttempts($old, $new));
    }

    /**
     * Events affected by a change of a child's level (their division changes with it).
     *
     * @return list<array{workshop: string, kind: string, children: list<Registration>}>
     */
    public function childImpact(CompetitionConfig $config, Registration $registration, ChildData $updated): array
    {
        if ($updated->level === $registration->child->level) {
            return [];
        }

        return ConfigImpact::compare(
            $config,
            $config,
            [['registration' => $registration, 'before' => $registration->child->level, 'after' => $updated->level]],
            $this->allAttempts($config, $config),
        );
    }

    /**
     * Whether a child has results, which makes a change of level or gender need a confirmation.
     */
    public function hasResults(CompetitionConfig $config, Registration $registration): bool
    {
        $attempts = $this->attempts->currentByChild(array_map(static fn(Variant $v): string => $v->id(), $config->allVariants()));

        return ($attempts[$registration->id] ?? []) !== [];
    }

    /**
     * Moves a child's results to the events of their new level group.
     *
     * @return int number of attempts moved
     */
    public function moveResults(CompetitionConfig $config, Registration $registration, string $newLevel): int
    {
        $moved = 0;
        foreach ($config->workshops as $workshop) {
            $from = ConfigImpact::variantOf($config, $workshop->id, $registration->child->level);
            $to = ConfigImpact::variantOf($config, $workshop->id, $newLevel);
            if ($from !== null && $to !== null && ConfigImpact::follows($from, $to)) {
                $moved += $this->attempts->moveVariant($registration->id, $from->id(), $to->id());
            }
        }

        return $moved;
    }

    /**
     * Shows a "settings changed" banner on the entry screen of these workshops.
     *
     * @param list<string> $workshops
     */
    public function alertStations(array $workshops, int $at): void
    {
        foreach (array_unique($workshops) as $workshop) {
            $this->state->set(self::ALERT_KEY . $workshop, (string) $at);
        }
    }

    /**
     * Time of the last alert for a workshop (Unix time), if any.
     */
    public function stationAlert(string $workshop): ?int
    {
        $value = $this->state->get(self::ALERT_KEY . $workshop);

        return $value === null || !ctype_digit($value) ? null : (int) $value;
    }

    private static function find(CompetitionConfig $config, string $id): ?Variant
    {
        foreach ($config->allVariants() as $variant) {
            if ($variant->id() === $id) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @return list<Registration>
     */
    private function children(): array
    {
        return array_values(array_filter(
            $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]),
            static fn(Registration $r): bool => $r->bib !== null,
        ));
    }

    /**
     * @return array<int, list<StoredAttempt>>
     */
    private function allAttempts(CompetitionConfig $old, CompetitionConfig $new): array
    {
        $ids = [];
        foreach ([...$old->allVariants(), ...$new->allVariants()] as $variant) {
            $ids[$variant->id()] = true;
        }

        return $this->attempts->currentByChild(array_map(strval(...), array_keys($ids)));
    }
}
