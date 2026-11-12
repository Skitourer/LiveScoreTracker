<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Workshop;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * How far the competition has gone (spec § 13.1, § 11.5): for each workshop,
 * the present children who went through it (at least one attempt) out of
 * the present children expected there, overall and by category. Only
 * numbers: no personal data.
 */
final class Progress
{
    /** Number of the least advanced workshops pointed out to send children there. */
    public const HIGHLIGHTED = 2;

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
    ) {}

    /**
     * @return array{
     *     workshops: list<array{workshop: Workshop, done: int, expected: int, highlighted: bool, categories: array<string, array{done: int, expected: int}>}>,
     *     done: int,
     *     expected: int,
     *     categories: list<Category>
     * }
     */
    public function compute(): array
    {
        $present = array_values(array_filter(
            $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]),
            static fn(Registration $child): bool => $child->bib !== null && $child->isPresent(),
        ));
        $variantIds = array_map(static fn($variant): string => $variant->id(), $this->config->variants());
        $done = [];
        foreach ($this->attempts->currentByChild($variantIds) as $childId => $list) {
            foreach ($list as $attempt) {
                $done[$childId][$attempt->variantId] = true;
            }
        }

        $rows = [];
        $totalDone = 0;
        $totalExpected = 0;
        foreach ($this->config->workshops as $workshop) {
            $doneBy = [];
            $expectedBy = [];
            foreach ($present as $child) {
                $group = $this->config->groupOf($child->child->level);
                $variant = null;
                foreach ($workshop->variants as $candidate) {
                    if ($candidate->group === $group) {
                        $variant = $candidate;
                    }
                }
                if ($variant === null) {
                    continue;
                }
                $category = $this->config->category($child->child->level, $child->child->gender)->id();
                $expectedBy[$category] = ($expectedBy[$category] ?? 0) + 1;
                $doneBy[$category] = ($doneBy[$category] ?? 0) + (isset($done[$child->id][$variant->id()]) ? 1 : 0);
            }
            $categories = [];
            foreach ($expectedBy as $category => $expected) {
                $categories[$category] = ['done' => $doneBy[$category] ?? 0, 'expected' => $expected];
            }
            $rowDone = array_sum($doneBy);
            $rowExpected = array_sum($expectedBy);
            $totalDone += $rowDone;
            $totalExpected += $rowExpected;
            $rows[] = ['workshop' => $workshop, 'done' => $rowDone, 'expected' => $rowExpected, 'categories' => $categories];
        }

        // The least advanced workshops that still have children to see.
        $ratios = [];
        foreach ($rows as $index => $row) {
            if ($row['done'] < $row['expected']) {
                $ratios[$index] = $row['done'] / $row['expected'];
            }
        }
        asort($ratios);
        $highlighted = \array_slice(array_keys($ratios), 0, self::HIGHLIGHTED);
        $workshops = [];
        foreach ($rows as $index => $row) {
            $workshops[] = [...$row, 'highlighted' => \in_array($index, $highlighted, true)];
        }

        return ['workshops' => $workshops, 'done' => $totalDone, 'expected' => $totalExpected, 'categories' => $this->config->categories()];
    }
}
