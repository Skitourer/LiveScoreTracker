<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use LiveScoreTracker\Domain\Registration\RegistrationSource;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Security\User;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;

/**
 * Spreadsheet import of registrations (spec § 5.7).
 *
 * plan() maps the configured columns, cleans and checks every line and sorts
 * them into new / already known / errors / duplicates, without saving anything.
 * apply() then adds the new children, in file order, with the next bibs.
 */
final class RegistrationImport
{
    /** Internal fields that may be mapped to a column; the first four are mandatory. */
    public const FIELDS = ['last_name', 'first_name', 'gender', 'level', 'birth_date', 'school', 'guardian_name', 'guardian_phone', 'guardian_email', 'volunteer'];
    private const MANDATORY = ['last_name', 'first_name', 'gender', 'level'];

    public function __construct(
        private readonly CompetitionConfig $config,
        private readonly ChildValidator $validator,
        private readonly RegistrationRepository $registrations,
    ) {}

    /**
     * @param list<string>                                          $headers
     * @param list<array{line: int, cells: array<string, string>}> $rows
     *
     * @return array{
     *     missing_columns: list<string>,
     *     columns: array<string, string>,
     *     lines: list<array{line: int, state: string, name: string, data: array<string, string|bool|null>|null, errors: array<string, array<string, string|int>>, warnings: array<string, array<string, string|int>>, bib: int|null}>,
     *     counts: array<string, int>
     * }
     */
    public function plan(array $headers, array $rows): array
    {
        $columns = $this->mapColumns($headers);
        $missing = array_values(array_diff(self::MANDATORY, array_keys($columns)));
        $lines = [];
        $seen = [];

        if ($missing === []) {
            foreach ($rows as $row) {
                $input = [];
                foreach (self::FIELDS as $field) {
                    $input[$field] = isset($columns[$field]) ? ($row['cells'][$columns[$field]] ?? '') : '';
                }
                $name = TextCleaner::spaces($input['first_name'] . ' ' . $input['last_name']);
                $result = $this->validator->validate($input, birthDateRequired: false, strictSchool: false);
                $line = ['line' => $row['line'], 'name' => $name, 'data' => null, 'errors' => $result->errors, 'warnings' => $result->warnings, 'bib' => null];

                if ($result->data === null) {
                    $lines[] = ['state' => 'error', ...$line];
                    continue;
                }
                $child = $result->data;
                $key = $child->matchKey() . '|' . ($child->birthDate ?? '');
                $known = $this->knownRegistration($child);

                if ($known !== null) {
                    $lines[] = ['state' => 'known', ...$line, 'bib' => $known];
                } elseif (isset($seen[$key])) {
                    $lines[] = ['state' => 'duplicate', ...$line, 'warnings' => [...$line['warnings'], 'import.duplicate_in_file' => ['line' => $seen[$key]]]];
                } else {
                    $seen[$key] = $row['line'];
                    $probable = $this->registrations->findDuplicate($child);
                    if ($probable !== null) {
                        $line['warnings']['import.probable_duplicate'] = ['bib' => $probable->bib ?? '–', 'name' => $probable->fullName()];
                    }
                    $lines[] = ['state' => 'new', ...$line, 'data' => $child->toArray()];
                }
            }
        }

        $counts = ['new' => 0, 'known' => 0, 'error' => 0, 'duplicate' => 0];
        foreach ($lines as $line) {
            ++$counts[$line['state']];
        }

        return ['missing_columns' => $missing, 'columns' => $columns, 'lines' => $lines, 'counts' => $counts];
    }

    /**
     * Adds the new children of a plan.
     *
     * @param list<array<string, string|bool|null>> $children data of the "new" lines, in file order
     *
     * @return int number of children added
     */
    public function apply(array $children, ?User $actor): int
    {
        $added = 0;
        foreach ($children as $data) {
            $child = self::childFromArray($data);
            // Re-check: the list may have changed since the preview.
            if ($this->knownRegistration($child) !== null) {
                continue;
            }
            $this->registrations->create($child, RegistrationStatus::Registered, RegistrationSource::Import, $actor, $this->config->registration->firstBib);
            ++$added;
        }

        return $added;
    }

    /**
     * Configured column headers found in the file: internal field => header as written in the file.
     *
     * @param list<string> $headers
     *
     * @return array<string, string>
     */
    public function mapColumns(array $headers): array
    {
        $byKey = [];
        foreach ($headers as $header) {
            $byKey[TextCleaner::key($header)] = $header;
        }
        $columns = [];
        foreach (self::FIELDS as $field) {
            $configured = $this->config->importColumns[$field] ?? null;
            if ($configured !== null && isset($byKey[TextCleaner::key($configured)])) {
                $columns[$field] = $byKey[TextCleaner::key($configured)];
            }
        }

        return $columns;
    }

    /**
     * Bib (or 0) of the existing registration of this child: same last name, first name
     * and level, and same birth date when both are known (spec § 5.7).
     */
    private function knownRegistration(ChildData $child): ?int
    {
        foreach ($this->registrations->findByMatchKey($child->matchKey()) as $existing) {
            if ($child->birthDate === null || $existing->child->birthDate === null || $existing->child->birthDate === $child->birthDate) {
                return $existing->bib ?? 0;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|bool|null> $data
     */
    private static function childFromArray(array $data): ChildData
    {
        $text = static fn(string $key): string => \is_string($data[$key] ?? null) ? $data[$key] : '';

        return new ChildData(
            lastName: $text('last_name'),
            firstName: $text('first_name'),
            birthDate: \is_string($data['birth_date'] ?? null) ? $data['birth_date'] : null,
            gender: $text('gender'),
            school: $text('school'),
            level: $text('level'),
            guardianName: $text('guardian_name'),
            guardianPhone: $text('guardian_phone'),
            guardianEmail: $text('guardian_email'),
            volunteer: ($data['volunteer'] ?? false) === true,
        );
    }
}
