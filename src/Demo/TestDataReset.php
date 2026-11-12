<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use PDO;

/**
 * Replaces the registrations of a TEST site by FICTITIOUS children, ready for
 * the start of the competition: most of them registered with a bib, a few on
 * the waiting list (no bib), nobody checked in yet, no result.
 *
 * Accounts, configuration, phase and audit log are kept. Never available in
 * production (checked by the caller).
 */
final class TestDataReset
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RegistrationRepository $registrations,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * @param int $count          children to create
     * @param int $withoutBibRate % of them left on the waiting list, without a bib
     *
     * @return array{registered: int, waitlisted: int}
     */
    public function reset(?User $actor, int $count = 100, int $withoutBibRate = 5, int $seed = 2027): array
    {
        foreach (['attempts', 'registration_history', 'registrations'] as $table) {
            $this->pdo->exec('DELETE FROM ' . $table);
        }

        $children = (new FictitiousCompetition($this->config, $seed, $count))->children;
        $waitlisted = (int) round($count * $withoutBibRate / 100);
        $result = ['registered' => 0, 'waitlisted' => 0];
        foreach ($children as $i => $child) {
            // The last ones, as the latest registrations, wait for a place.
            $status = $i >= $count - $waitlisted ? RegistrationStatus::Waitlisted : RegistrationStatus::Registered;
            $this->registrations->create(
                new ChildData(
                    lastName: $child->lastName,
                    firstName: $child->firstName,
                    birthDate: $child->birthDate->format('Y-m-d'),
                    gender: $child->gender,
                    school: $child->school,
                    level: $child->level,
                    guardianName: 'Parent ' . $child->lastName,
                    guardianPhone: '06' . str_pad((string) (12345678 + $i * 7919 % 87654321), 8, '0', STR_PAD_LEFT),
                    guardianEmail: 'parent' . ($i + 1) . '@example.org',
                    volunteer: $i % 8 === 0,
                ),
                $status,
                RegistrationSource::Import,
                $actor,
                $this->config->registration->firstBib,
            );
            ++$result[$status === RegistrationStatus::Registered ? 'registered' : 'waitlisted'];
        }

        return $result;
    }
}
