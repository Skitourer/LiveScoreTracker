<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Spreadsheet;

use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel export of the registrations list (spec § 5.9).
 */
final class RegistrationExport
{
    private const COLUMNS = [
        'bib', 'status', 'last_name', 'first_name', 'birth_date', 'gender', 'level', 'school',
        'guardian_name', 'guardian_phone', 'guardian_email', 'volunteer', 'source',
    ];

    public function __construct(private readonly Translator $translator) {}

    /**
     * @param list<Registration> $registrations
     *
     * @return string the .xlsx file content
     */
    public function xlsx(array $registrations): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($this->translator->trans('registration.title'), 0, 31));

        $header = array_map(fn(string $column): string => $this->translator->trans('registration.field.' . $column), self::COLUMNS);
        $sheet->fromArray([$header], null, 'A1');
        $sheet->getStyle('A1:M1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $rowNumber = 2;
        foreach ($registrations as $registration) {
            $child = $registration->child;
            $sheet->fromArray([[
                $registration->bib,
                $this->translator->trans('registration.status.' . $registration->status->value),
                $child->lastName,
                $child->firstName,
                TextCleaner::displayDate($child->birthDate),
                $this->translator->trans("gender.{$child->gender}.one"),
                $child->level,
                $child->school,
                $child->guardianName,
                $child->guardianPhone,
                $child->guardianEmail,
                $child->volunteer ? $this->translator->trans('registration.yes') : '',
                $this->translator->trans('registration.source.' . $registration->source->value),
            ]], null, 'A' . $rowNumber);
            // Phone numbers stay text (keeps the leading zero).
            $sheet->getCell('J' . $rowNumber)->setValueExplicit($child->guardianPhone, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            ++$rowNumber;
        }
        foreach (range('A', 'M') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create the export.');
        }
        (new Xlsx($spreadsheet))->save($stream);
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
