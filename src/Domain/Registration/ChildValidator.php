<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

use LiveScoreTracker\Domain\Config\CompetitionConfig;

/**
 * Cleans and checks the fields of a registration (spec § 5.1).
 *
 * Errors block the registration; warnings (e.g. birth date unusual for the
 * class) are shown but accepted.
 */
final class ChildValidator
{
    public const FIELDS = [
        'last_name', 'first_name', 'birth_date', 'gender', 'school', 'level',
        'guardian_name', 'guardian_phone', 'guardian_email', 'volunteer',
    ];

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly ValueMatcher $matcher,
    ) {}

    /**
     * @param array<string, string> $input        raw values by field name
     * @param bool                  $birthDateRequired false for children added on site (spec § 5.8)
     * @param bool                  $strictSchool unknown school: error (forms) or warning, value kept (imports)
     */
    public function validate(array $input, bool $birthDateRequired = true, bool $strictSchool = true): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $value = static fn(string $field): string => TextCleaner::spaces($input[$field] ?? '');

        foreach (['last_name', 'first_name', 'gender', 'school', 'level', 'guardian_name', 'guardian_phone', 'guardian_email'] as $field) {
            if ($value($field) === '') {
                $errors['registration.required.' . $field] = [];
            }
        }
        if ($birthDateRequired && $value('birth_date') === '') {
            $errors['registration.required.birth_date'] = [];
        }

        $birthDate = null;
        if ($value('birth_date') !== '') {
            $birthDate = TextCleaner::date($value('birth_date'));
            if ($birthDate === null) {
                $errors['registration.date_invalid'] = ['value' => $value('birth_date')];
            }
        }

        $gender = $value('gender') === '' ? null : $this->matcher->gender($value('gender'));
        if ($value('gender') !== '' && $gender === null) {
            $errors['registration.gender_unknown'] = ['value' => $value('gender')];
        }

        $level = $value('level') === '' ? null : $this->matcher->level($value('level'));
        if ($value('level') !== '' && $level === null) {
            $errors['registration.level_unknown'] = ['value' => $value('level')];
        }

        $school = $value('school');
        if ($school !== '' && $this->config->schools !== []) {
            $known = $this->matcher->school($school);
            if ($known !== null) {
                $school = $known;
            } elseif ($strictSchool) {
                $errors['registration.school_unknown'] = ['value' => $school];
            } else {
                $warnings['registration.school_unknown'] = ['value' => $school];
            }
        }

        $phone = TextCleaner::phone($value('guardian_phone'));
        if ($value('guardian_phone') !== '' && !TextCleaner::isPhone($phone)) {
            $errors['registration.phone_invalid'] = ['value' => $value('guardian_phone')];
        }
        $email = TextCleaner::email($value('guardian_email'));
        if ($email !== '' && !TextCleaner::isEmail($email)) {
            $errors['registration.email_invalid'] = ['value' => $value('guardian_email')];
        }

        if ($birthDate !== null && $level !== null) {
            $expectedYear = $this->expectedBirthYear($level);
            if ($expectedYear !== null && (int) substr($birthDate, 0, 4) !== $expectedYear) {
                $warnings['registration.age_unusual'] = ['level' => $level, 'year' => $expectedYear];
            }
        }

        if ($errors !== [] || $gender === null || $level === null) {
            return new ValidationResult(null, $errors, $warnings);
        }

        return new ValidationResult(new ChildData(
            lastName: TextCleaner::lastName($value('last_name')),
            firstName: TextCleaner::firstName($value('first_name')),
            birthDate: $birthDate,
            gender: $gender,
            school: $school,
            level: $level,
            guardianName: TextCleaner::spaces($value('guardian_name')),
            guardianPhone: $phone,
            guardianEmail: $email,
            volunteer: TextCleaner::yes($value('volunteer')),
        ), [], $warnings);
    }

    /**
     * Usual birth year for a level: children of the first level turn 7 in the
     * calendar year of the edition, one more year for each following level.
     */
    private function expectedBirthYear(string $level): ?int
    {
        $index = array_search($level, $this->config->levels, true);

        return $index === false ? null : (int) $this->config->edition->date->format('Y') - 7 - $index;
    }
}
