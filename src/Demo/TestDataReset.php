<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\AttendanceMark;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\Author;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Replaces the registrations of a TEST site by FICTITIOUS children, ready for
 * the start of the competition: most of them registered with a bib, a few on
 * the waiting list (no bib), most already checked in (bib handed out), no result.
 *
 * Accounts, configuration, phase and audit log are kept. Never available in
 * production (checked by the caller).
 */
final class TestDataReset
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * @param int $count          children to create
     * @param int $withoutBibRate % of them left on the waiting list, without a bib
     * @param int  $presentRate    % of them already checked in (taken among those with a bib)
     * @param bool $withResults    also realistic results for the present children, about 3 events out of 4
     *
     * @return array{registered: int, waitlisted: int, present: int, results: int}
     */
    public function reset(?User $actor, int $count = 100, int $withoutBibRate = 5, int $presentRate = 90, bool $withResults = false, int $seed = 2027): array
    {
        foreach (['attempts', 'registration_history', 'registrations'] as $table) {
            $this->pdo->exec('DELETE FROM ' . $table);
        }

        $competition = new FictitiousCompetition($this->config, $seed, $count);
        $children = $competition->children;
        $waitlisted = (int) round($count * $withoutBibRate / 100);
        $result = ['registered' => 0, 'waitlisted' => 0, 'present' => 0, 'results' => 0];
        $withBib = [];
        foreach ($children as $i => $child) {
            // The last ones, as the latest registrations, wait for a place.
            $status = $i >= $count - $waitlisted ? RegistrationStatus::Waitlisted : RegistrationStatus::Registered;
            $id = $this->registrations->create(
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
            if ($status === RegistrationStatus::Registered) {
                $withBib[] = $id;
            }
        }

        // Children already arrived at reception, picked at random (same seed, same children).
        $present = min(\count($withBib), (int) round($count * $presentRate / 100));
        $shuffled = (new Randomizer(new Mt19937($seed)))->shuffleArray($withBib);
        foreach (\array_slice($shuffled, 0, $present) as $id) {
            $this->registrations->setAttendance($id, AttendanceMark::CheckedIn, true, $actor);
            ++$result['present'];
            if ($withResults) {
                $result['results'] += $this->results($competition, $id, $actor);
            }
        }

        return $result;
    }

    /**
     * Results of one present child, taken from the fictitious competition; about one event
     * in four is left to do, so that the follow-up and missing-events screens have content.
     */
    private function results(FictitiousCompetition $competition, int $registrationId, ?User $actor): int
    {
        $child = $this->registrations->find($registrationId);
        if ($child === null || $child->bib === null) {
            return 0;
        }
        $author = new Author($actor?->id, $actor?->username, null, null);
        $count = 0;
        foreach ($this->config->variantsForGroup($this->config->groupOf($child->child->level)) as $variant) {
            if (crc32($child->bib . '|' . $variant->id()) % 4 === 0) {
                continue;
            }
            foreach ($competition->attemptsOf($variant->id(), (string) $child->bib) as $attempt) {
                $this->attempts->record(bin2hex(random_bytes(16)), $child->id, $child->bib, $variant->id(), $attempt->result(), [], 0, [], $author);
                ++$count;
            }
        }

        return $count;
    }
}
