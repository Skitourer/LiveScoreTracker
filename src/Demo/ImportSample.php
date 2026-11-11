<?php

declare(strict_types=1);

namespace LiveScoreTracker\Demo;

use LiveScoreTracker\Domain\Config\CompetitionConfig;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Spreadsheet of FICTITIOUS registrations, with the configured column headers,
 * to try the import (step 3). Some values are deliberately untidy to show the
 * cleaning, and the last lines contain an error and a duplicate.
 */
final class ImportSample
{
    public function __construct(private readonly CompetitionConfig $config) {}

    public function xlsx(int $count = 40, int $seed = 3): string
    {
        $fields = ['last_name', 'first_name', 'birth_date', 'gender', 'level', 'school', 'guardian_name', 'guardian_phone', 'guardian_email', 'volunteer'];
        $fields = array_values(array_filter($fields, fn(string $f): bool => isset($this->config->importColumns[$f])));
        $rows = [array_map(fn(string $f): string => $this->config->importColumns[$f], $fields)];

        $competition = new FictitiousCompetition($this->config, $seed, $count);
        foreach ($competition->children as $i => $child) {
            $values = [
                'last_name' => $i % 4 === 0 ? mb_strtolower($child->lastName) : $child->lastName,
                'first_name' => $i % 5 === 0 ? '  ' . mb_strtoupper($child->firstName) . ' ' : $child->firstName,
                'birth_date' => $child->birthDate->format($i % 2 === 0 ? 'd/m/Y' : 'Y-m-d'),
                'gender' => match ($i % 3) {
                    0 => $child->gender === 'girl' ? 'Fille' : 'Garçon',
                    1 => $child->gender === 'girl' ? 'F' : 'garcon',
                    default => $child->gender,
                },
                'level' => $i % 6 === 0 ? mb_strtolower(substr($child->level, 0, 2)) . ' ' . substr($child->level, 2) : $child->level,
                'school' => $child->school,
                'guardian_name' => 'Parent ' . $child->lastName,
                'guardian_phone' => '6' . str_pad((string) (10000000 + $i * 7919 % 89999999), 8, '0', STR_PAD_LEFT),
                'guardian_email' => 'parent' . ($i + 1) . '@example.org',
                'volunteer' => $i % 7 === 0 ? 'Oui' : '',
            ];
            $rows[] = array_map(static fn(string $f): string => $values[$f], $fields);
        }

        // An unknown class (error) and the first child again (duplicate within the file).
        $broken = $rows[1];
        $levelIndex = array_search('level', $fields, true);
        if ($levelIndex !== false) {
            $broken[$levelIndex] = '6e';
        }
        $rows[] = $broken;
        $rows[] = $rows[2];

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create the sample.');
        }
        (new Xlsx($spreadsheet))->save($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
