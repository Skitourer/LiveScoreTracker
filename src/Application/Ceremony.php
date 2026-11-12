<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\Category;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Infrastructure\Persistence\AppState;

/**
 * Award ceremony (spec § 13.2), after the closing: a big screen shows the
 * podium of one category at a time, revealed from the 3rd place to the 1st,
 * as first name + initial with the total of points. A phone of an admin or
 * organizer drives it: next place, previous, next category, order of the
 * categories. The state is kept in the database so that the screen and the
 * phone share it.
 */
final class Ceremony
{
    private const KEY = 'ceremony';

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly AppState $appState,
        private readonly Standings $standings,
    ) {}

    /**
     * Where the ceremony stands, with the podium of the current category.
     *
     * @return array{
     *     order: list<Category>,
     *     index: int,
     *     category: Category|null,
     *     steps: list<list<array{rank: int, name: string, total: int}>>,
     *     revealed: int
     * }
     */
    public function current(): array
    {
        [$order, $index, $revealed] = $this->load();
        $category = $order[$index] ?? null;
        $steps = $category === null ? [] : $this->podium($category);

        return ['order' => $order, 'index' => $index, 'category' => $category, 'steps' => $steps, 'revealed' => min($revealed, \count($steps))];
    }

    /** Reveals the next place of the podium (3rd, then 2nd, then 1st). */
    public function reveal(): void
    {
        $state = $this->current();
        $this->save($state['order'], $state['index'], min($state['revealed'] + 1, \count($state['steps'])));
    }

    /** Hides the last place revealed (a tap too many). */
    public function hide(): void
    {
        $state = $this->current();
        $this->save($state['order'], $state['index'], max($state['revealed'] - 1, 0));
    }

    /** Goes to another category (by its position in the order), nothing revealed. */
    public function goTo(int $index): void
    {
        $state = $this->current();
        $this->save($state['order'], max(0, min($index, \count($state['order']) - 1)), 0);
    }

    /** Moves a category one place up (-1) or down (+1) in the order. */
    public function move(string $categoryId, int $direction): void
    {
        $state = $this->current();
        $ids = array_map(static fn(Category $category): string => $category->id(), $state['order']);
        $from = array_search($categoryId, $ids, true);
        $to = \is_int($from) ? $from + $direction : -1;
        if (!\is_int($from) || $to < 0 || $to >= \count($ids)) {
            return;
        }
        $current = $state['category']?->id();
        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        $order = $this->ordered(array_values($ids));
        $index = array_search($current, $ids, true);
        $this->save($order, \is_int($index) ? $index : 0, $state['revealed']);
    }

    /**
     * Podium of a category: present children ranked 1 to 3, grouped by place
     * (an unbroken tie shares a place), in the order they are revealed: 3rd first.
     *
     * @return list<list<array{rank: int, name: string, total: int}>>
     */
    private function podium(Category $category): array
    {
        $standings = $this->standings->forCategory($category, true);
        $places = [];
        foreach ($standings['ranking'] as $line) {
            if (!$line->present || $line->rank === null || $line->rank > 3) {
                continue;
            }
            $child = $standings['children'][(int) $line->bib] ?? null;
            $places[$line->rank][] = ['rank' => $line->rank, 'name' => $child?->publicName() ?? $line->bib, 'total' => $line->total];
        }
        krsort($places);

        return array_values($places);
    }

    /**
     * @return array{0: list<Category>, 1: int, 2: int}
     */
    private function load(): array
    {
        $data = json_decode($this->appState->get(self::KEY) ?? '', true);
        $data = \is_array($data) ? $data : [];
        $ids = \is_array($data['order'] ?? null) ? array_values(array_filter($data['order'], \is_string(...))) : [];
        $order = $this->ordered($ids);
        $index = \is_int($data['index'] ?? null) ? max(0, min($data['index'], \count($order) - 1)) : 0;
        $revealed = \is_int($data['revealed'] ?? null) ? max(0, $data['revealed']) : 0;

        return [$order, $index, $revealed];
    }

    /**
     * Categories in the saved order, then any category the order does not know yet
     * (default: the order of the configuration).
     *
     * @param list<string> $ids
     *
     * @return list<Category>
     */
    private function ordered(array $ids): array
    {
        $byId = [];
        foreach ($this->config->categories() as $category) {
            $byId[$category->id()] = $category;
        }
        $order = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $order[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        return [...$order, ...array_values($byId)];
    }

    /**
     * @param list<Category> $order
     */
    private function save(array $order, int $index, int $revealed): void
    {
        $this->appState->set(self::KEY, json_encode([
            'order' => array_map(static fn(Category $category): string => $category->id(), $order),
            'index' => $index,
            'revealed' => $revealed,
        ], JSON_THROW_ON_ERROR));
    }
}
