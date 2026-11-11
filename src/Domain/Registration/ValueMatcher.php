<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

use LiveScoreTracker\Domain\Config\CompetitionConfig;

/**
 * Recognizes the variants of configured values (spec § 5.7):
 * « Garçon », « garcon », « G » → boy; « cm 1 » → CM1; school names without accents.
 */
final class ValueMatcher
{
    /**
     * Common ways of writing the two default genders.
     */
    private const GENDER_ALIASES = [
        'girl' => ['fille', 'filles', 'f', 'feminin', 'femme', 'girl', 'girls'],
        'boy' => ['garcon', 'garcons', 'g', 'm', 'masculin', 'homme', 'boy', 'boys'],
    ];

    /** @var array<string, string> comparison key => gender id */
    private array $genders = [];

    /**
     * @param array<string, list<string>> $genderLabels gender id => displayed labels (translations)
     */
    public function __construct(
        private readonly CompetitionConfig $config,
        array $genderLabels = [],
    ) {
        foreach ($config->genders as $gender) {
            $names = [$gender, ...(self::GENDER_ALIASES[$gender] ?? []), ...($genderLabels[$gender] ?? [])];
            foreach ($names as $name) {
                $this->genders[TextCleaner::key($name)] ??= $gender;
            }
        }
    }

    public function gender(string $value): ?string
    {
        return $this->genders[TextCleaner::key($value)] ?? null;
    }

    public function level(string $value): ?string
    {
        $key = TextCleaner::key($value);
        foreach ($this->config->levels as $level) {
            if (TextCleaner::key($level) === $key) {
                return $level;
            }
        }

        return null;
    }

    /**
     * The configured spelling of a school, or null when the school is not in the list.
     */
    public function school(string $value): ?string
    {
        $key = TextCleaner::key($value);
        foreach ($this->config->schools as $school) {
            if (TextCleaner::key($school) === $key) {
                return $school;
            }
        }

        return null;
    }
}
