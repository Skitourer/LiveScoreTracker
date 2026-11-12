<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use DateInterval;
use DateTimeImmutable;
use LiveScoreTracker\Domain\Config\Better;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Measure;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Scoring\Attempt;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generates a realistic competition with fictitious children and results (spec § 25.2).
 *
 * The same seed always gives the same data, so a report can be reproduced.
 */
final class FictitiousCompetition
{
    private const GIRL_NAMES = ['Alice', 'Chloé', 'Emma', 'Inès', 'Jade', 'Léa', 'Lina', 'Louise', 'Manon', 'Mila', 'Nina', 'Rose', 'Sarah', 'Zoé', 'Anna', 'Lou', 'Iris', 'Margot'];
    private const BOY_NAMES = ['Adam', 'Arthur', 'Gabriel', 'Hugo', 'Jules', 'Léo', 'Liam', 'Louis', 'Lucas', 'Nathan', 'Noah', 'Paul', 'Raphaël', 'Sacha', 'Tom', 'Victor', 'Malo', 'Nino'];
    private const LAST_NAMES = ['Arnaud', 'Blanc', 'Caron', 'Denis', 'Faure', 'Garnier', 'Henry', 'Joly', 'Lambert', 'Marchand', 'Noël', 'Olivier', 'Perrin', 'Renard', 'Roux', 'Vidal', 'Weber', 'Bertin'];

    private const ABSENT_RATE = 5;   // % of children absent
    private const DNF_RATE = 3;      // % of events not done by a present child
    private const FOUL_RATE = 7;     // % of attempts that are fouls
    private const MODIFIER_RATE = 40; // % of attempts earning each modifier

    /** @var list<FictitiousChild> */
    public readonly array $children;

    /**
     * Attempts per event and child: variant id => (bib => attempts).
     * Children absent from an event's list have no attempt yet.
     *
     * @var array<string, array<string, list<Attempt>>>
     */
    public readonly array $attempts;

    private readonly Randomizer $random;

    public function __construct(
        private readonly CompetitionConfig $config,
        public readonly int $seed,
        int $count = 100,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->children = $this->generateChildren($count);
        $this->attempts = $this->generateAttempts();
    }

    /**
     * Attempts of one child at one event (none when the event is not done).
     *
     * @return list<Attempt>
     */
    public function attemptsOf(string $variantId, string $bib): array
    {
        /** @var array<array-key, list<Attempt>> $byBib */
        $byBib = $this->attempts[$variantId] ?? [];

        return $byBib[$bib] ?? [];
    }

    /**
     * @return list<FictitiousChild>
     */
    private function generateChildren(int $count): array
    {
        $children = [];
        $year = (int) $this->config->edition->date->format('Y');
        $schools = $this->config->schools === [] ? ['School'] : $this->config->schools;

        for ($i = 0; $i < $count; ++$i) {
            $levelIndex = $this->random->getInt(0, \count($this->config->levels) - 1);
            $gender = $this->pick($this->config->genders);
            $names = $gender === ($this->config->genders[0] ?? '') ? self::GIRL_NAMES : self::BOY_NAMES;
            // A child in the first level is about 7 at the end of the school year.
            $birthDate = (new DateTimeImmutable(\sprintf('%d-01-01', $year - 7 - $levelIndex)))
                ->add(new DateInterval('P' . $this->random->getInt(0, 364) . 'D'));

            $children[] = new FictitiousChild(
                bib: (string) ($this->config->registration->firstBib + $i),
                firstName: $this->pick($names),
                lastName: $this->pick(self::LAST_NAMES),
                level: $this->config->levels[$levelIndex],
                gender: $gender,
                school: $this->pick($schools),
                birthDate: $birthDate,
                present: !$this->chance(self::ABSENT_RATE),
            );
        }

        return $children;
    }

    /**
     * @return array<string, array<string, list<Attempt>>>
     */
    private function generateAttempts(): array
    {
        $attempts = [];
        foreach ($this->children as $child) {
            if (!$child->present) {
                continue;
            }
            $levelIndex = (int) array_search($child->level, $this->config->levels, true);
            $levelBonus = $levelIndex / max(1, \count($this->config->levels) - 1);
            $ability = min(1.0, max(0.0, $this->normal(0.45 + 0.2 * $levelBonus, 0.18)));

            foreach ($this->config->variantsForGroup($this->config->groupOf($child->level)) as $variant) {
                if ($this->chance(self::DNF_RATE)) {
                    continue;
                }
                $list = [];
                for ($n = 0; $n < $variant->attempts; ++$n) {
                    $list[] = $this->attempt($variant, $ability);
                }
                $attempts[$variant->id()][$child->bib] = $list;
            }
        }

        return $attempts;
    }

    private function attempt(Variant $variant, float $ability): Attempt
    {
        if ($variant->measure !== Measure::Counter && $this->chance(self::FOUL_RATE)) {
            return Attempt::foul();
        }

        // Performance between 0 (weak) and 1 (strong), with some noise per attempt.
        $level = min(1.0, max(0.0, $ability + $this->normal(0.0, 0.08)));

        if ($variant->measure === Measure::Counter) {
            $max = $variant->maxValue ?? 30;

            return Attempt::valid((int) round($level * $max * 0.7));
        }

        [$min, $max] = $variant->plausibleRange ?? [0, 1000];
        // Stay inside the plausible range, away from its extremes.
        $quality = 0.1 + 0.8 * $level;
        $value = $variant->better === Better::Higher
            ? $min + $quality * ($max - $min) * 0.6
            : $max - $quality * ($max - $min) * 0.6;

        $modifiers = 0;
        foreach ($variant->modifiers as $modifier) {
            if ($this->chance(self::MODIFIER_RATE)) {
                $modifiers += $modifier->value;
            }
        }

        return Attempt::valid((int) round($value), $modifiers);
    }

    /**
     * @template T
     *
     * @param non-empty-list<T>|list<T> $items
     *
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, \count($items) - 1)];
    }

    private function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    private function normal(float $mean, float $deviation): float
    {
        // Box-Muller transform on the seeded generator.
        $u = max(1e-9, $this->random->getInt(1, PHP_INT_MAX) / PHP_INT_MAX);
        $v = $this->random->getInt(0, PHP_INT_MAX) / PHP_INT_MAX;

        return $mean + $deviation * sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
    }
}
