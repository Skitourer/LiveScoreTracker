<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Scoring\RankedCompetitor;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * Content of the printed documents and exports (spec § 14): rescue sheets
 * (one per workshop and level group, children sorted by bib), podiums and
 * full rankings by category. Documents show full names: they are for admins
 * and organizers only.
 */
final class Documents
{
    /** Empty lines at the end of each rescue sheet, for children added after printing. */
    public const BLANK_LINES = 10;

    private const RESCUE_PRINTED = 'rescue_sheets_printed_at';

    /** Registrations already changed during the second of the printing (timestamps are to the second). */
    private const RESCUE_BASELINE = 'rescue_sheets_baseline';

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly Standings $standings,
        private readonly AppState $appState,
    ) {}

    /**
     * Rescue sheets: every registered child with a bib, by event of their level group.
     *
     * @return list<array{variant: Variant, levels: list<string>, children: list<Registration>}>
     */
    public function rescueSheets(): array
    {
        $children = $this->children();
        $sheets = [];
        foreach ($this->config->workshops as $workshop) {
            foreach ($workshop->variants as $variant) {
                $levels = $this->config->levelGroups[$variant->group] ?? [];
                $sheets[] = [
                    'variant' => $variant,
                    'levels' => $levels,
                    'children' => array_values(array_filter(
                        $children,
                        static fn(Registration $child): bool => \in_array($child->child->level, $levels, true),
                    )),
                ];
            }
        }

        return $sheets;
    }

    /**
     * Remembers when the rescue sheets were printed (UTC 'Y-m-d H:i:s').
     */
    public function markRescuePrinted(string $at): void
    {
        $this->appState->set(self::RESCUE_PRINTED, $at);
        $this->appState->set(self::RESCUE_BASELINE, (string) $this->registrations->countChangedSince($at));
    }

    /**
     * When the rescue sheets were last printed, and how many registrations changed since.
     *
     * @return array{printed_at: string, changed: int}|null
     */
    public function rescueFreshness(): ?array
    {
        $at = $this->appState->get(self::RESCUE_PRINTED);

        if ($at === null) {
            return null;
        }
        $baseline = (int) ($this->appState->get(self::RESCUE_BASELINE) ?? '0');

        return ['printed_at' => $at, 'changed' => max(0, $this->registrations->countChangedSince($at) - $baseline)];
    }

    /**
     * Full ranking of every category, as after the closing when it is closed.
     *
     * @return list<array{category: Category, children: array<int, Registration>, events: list<array{variant: Variant}>, ranking: list<RankedCompetitor>}>
     */
    public function rankings(bool $closed): array
    {
        $result = [];
        foreach ($this->config->categories() as $category) {
            $standings = $this->standings->forCategory($category, $closed);
            $result[] = [
                'category' => $category,
                'children' => $standings['children'],
                'events' => array_map(static fn(array $event): array => ['variant' => $event['variant']], $standings['events']),
                'ranking' => array_values(array_filter($standings['ranking'], static fn(RankedCompetitor $line): bool => $line->present)),
            ];
        }

        return $result;
    }

    /**
     * Podium of every category: present children ranked 1 to 3.
     *
     * @return list<array{category: Category, children: array<int, Registration>, lines: list<RankedCompetitor>}>
     */
    public function podiums(bool $closed): array
    {
        return array_map(static fn(array $category): array => [
            'category' => $category['category'],
            'children' => $category['children'],
            'lines' => array_values(array_filter($category['ranking'], static fn(RankedCompetitor $line): bool => $line->rank !== null && $line->rank <= 3)),
        ], $this->rankings($closed));
    }

    /**
     * Registered children with a bib, by bib.
     *
     * @return list<Registration>
     */
    private function children(): array
    {
        $children = array_values(array_filter(
            $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]),
            static fn(Registration $child): bool => $child->bib !== null,
        ));
        usort($children, static fn(Registration $a, Registration $b): int => $a->bib <=> $b->bib);

        return $children;
    }
}
