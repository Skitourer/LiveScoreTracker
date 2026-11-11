<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Registration;

/**
 * The fields of a registration (spec § 5.1), already cleaned.
 * Used by the manual form, the spreadsheet import and (later) the public form.
 */
final class ChildData
{
    /**
     * @param string|null $birthDate 'YYYY-MM-DD'; optional for children added on site
     */
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $birthDate,
        public readonly string $gender,
        public readonly string $school,
        public readonly string $level,
        public readonly string $guardianName,
        public readonly string $guardianPhone,
        public readonly string $guardianEmail,
        public readonly bool $volunteer,
    ) {}

    /**
     * Key identifying the same child across imports: last name + first name + level,
     * case and accent insensitive (spec § 5.7).
     */
    public function matchKey(): string
    {
        return TextCleaner::key($this->lastName) . '|' . TextCleaner::key($this->firstName) . '|' . TextCleaner::key($this->level);
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'last_name' => $this->lastName,
            'first_name' => $this->firstName,
            'birth_date' => $this->birthDate,
            'gender' => $this->gender,
            'school' => $this->school,
            'level' => $this->level,
            'guardian_name' => $this->guardianName,
            'guardian_phone' => $this->guardianPhone,
            'guardian_email' => $this->guardianEmail,
            'volunteer' => $this->volunteer,
        ];
    }
}
