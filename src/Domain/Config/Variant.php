<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * A workshop's version for one level group: the "event" used for scoring (spec § 7.1, § 7.2).
 *
 * All numeric values are in storage units: hundredths of a second for times,
 * centimetres for distances, points for scores and counters.
 */
final class Variant
{
    /**
     * @param array{0: int, 1: int}|null $plausibleRange
     * @param list<Modifier>              $modifiers
     * @param list<CounterAction>         $actions
     */
    public function __construct(
        public readonly string $workshopId,
        public readonly string $group,
        public readonly Measure $measure,
        public readonly Better $better,
        public readonly int $attempts,
        public readonly FoulMode $foulMode,
        public readonly ScoringRules $scoring,
        public readonly ?string $label = null,
        public readonly ?TimeFormat $timeFormat = null,
        public readonly ?array $plausibleRange = null,
        public readonly ?int $maxValue = null,
        public readonly array $modifiers = [],
        public readonly bool $builtInTimer = false,
        public readonly int $bibsPerTimer = 1,
        public readonly ?CounterLimit $limit = null,
        public readonly array $actions = [],
    ) {}

    /**
     * Stable identifier, e.g. "sprint.junior".
     */
    public function id(): string
    {
        return $this->workshopId . '.' . $this->group;
    }

    public function isPlausible(int $value): bool
    {
        return $this->plausibleRange === null
            || ($value >= $this->plausibleRange[0] && $value <= $this->plausibleRange[1]);
    }
}
