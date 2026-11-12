<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Results;

use LiveScoreTracker\Domain\Config\CounterLimitType;
use LiveScoreTracker\Domain\Config\Variant;

/**
 * What happened during a counter event (spec § 7.6, § 8.5): how many times
 * each action was made (e.g. 3 baskets, 2 rims) and the shots missed. The
 * result is the total of the points; the detail is kept with the attempt.
 */
final class CounterTally
{
    /** Upper bound per action, against typing mistakes. */
    public const MAX_COUNT = 999;

    /**
     * @param array<string, int> $counts action id => times made (only the actions made)
     */
    public function __construct(
        public readonly array $counts,
        public readonly int $missed = 0,
    ) {}

    /**
     * Rebuilds the tally stored with an attempt, or null when the attempt has none.
     *
     * @param array<string, mixed> $details
     */
    public static function fromDetails(array $details): ?self
    {
        $actions = $details['actions'] ?? null;
        if (!\is_array($actions)) {
            return null;
        }
        $counts = [];
        foreach ($actions as $id => $count) {
            if (\is_int($count) && $count > 0) {
                $counts[(string) $id] = $count;
            }
        }
        $missed = $details['missed'] ?? 0;

        return new self($counts, \is_int($missed) ? $missed : 0);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [] && $this->missed === 0;
    }

    /**
     * Points scored, using the points of each action configured on the event.
     */
    public function total(Variant $variant): int
    {
        $total = 0;
        foreach ($variant->actions as $action) {
            $total += $action->points * ($this->counts[$action->id] ?? 0);
        }

        return $total;
    }

    /**
     * Shots taken: actions made plus shots missed.
     */
    public function shots(): int
    {
        return array_sum($this->counts) + $this->missed;
    }

    /**
     * Non-blocking checks (spec § 10.1): more shots than allowed.
     *
     * @return list<string> warning codes
     */
    public function warnings(Variant $variant): array
    {
        $limit = $variant->limit;
        if ($limit !== null && $limit->type === CounterLimitType::Shots && $this->shots() > $limit->value) {
            return [EntryChecks::TOO_MANY_SHOTS];
        }

        return [];
    }

    /**
     * @return array{source: string, actions: array<string, int>, missed?: int}
     */
    public function toDetails(): array
    {
        $details = ['source' => 'counter', 'actions' => $this->counts];
        if ($this->missed > 0) {
            $details['missed'] = $this->missed;
        }

        return $details;
    }
}
