<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use InvalidArgumentException;

/**
 * The whole configuration of an edition (spec § 12.2), validated and immutable.
 */
final class CompetitionConfig
{
    /**
     * @param list<string>                $levels       display order
     * @param list<string>                $genders      display order
     * @param list<string>                $schools
     * @param array<string, list<string>> $levelGroups  group id => levels
     * @param list<Workshop>              $workshops
     * @param array<string, string>       $importColumns internal field => source column header
     * @param list<Contact>               $contacts
     */
    public function __construct(
        public readonly Edition $edition,
        public readonly array $levels,
        public readonly array $genders,
        public readonly array $schools,
        public readonly array $levelGroups,
        public readonly Registration $registration,
        public readonly ScoringRules $scoring,
        public readonly array $workshops,
        public readonly array $importColumns = [],
        public readonly array $contacts = [],
    ) {}

    /**
     * Categories in display order: by level, then by gender.
     *
     * @return list<Category>
     */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->levels as $level) {
            foreach ($this->genders as $gender) {
                $categories[] = new Category($level, $gender, $this->groupOf($level));
            }
        }

        return $categories;
    }

    public function category(string $level, string $gender): Category
    {
        if (!\in_array($gender, $this->genders, true)) {
            throw new InvalidArgumentException(\sprintf('Unknown gender "%s".', $gender));
        }

        return new Category($level, $gender, $this->groupOf($level));
    }

    public function groupOf(string $level): string
    {
        foreach ($this->levelGroups as $group => $levels) {
            if (\in_array($level, $levels, true)) {
                return $group;
            }
        }

        throw new InvalidArgumentException(\sprintf('Level "%s" belongs to no level group.', $level));
    }

    /**
     * Events of a level group, in workshop order.
     *
     * @return list<Variant>
     */
    public function variantsForGroup(string $group): array
    {
        $variants = [];
        foreach ($this->variants() as $variant) {
            if ($variant->group === $group) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /**
     * @return list<Variant>
     */
    public function variants(): array
    {
        $variants = [];
        foreach ($this->workshops as $workshop) {
            foreach ($workshop->variants as $variant) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    public function variant(string $id): Variant
    {
        foreach ($this->variants() as $variant) {
            if ($variant->id() === $id) {
                return $variant;
            }
        }

        throw new InvalidArgumentException(\sprintf('Unknown event "%s".', $id));
    }
}
