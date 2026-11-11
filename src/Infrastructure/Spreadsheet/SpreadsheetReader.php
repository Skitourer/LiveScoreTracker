<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

/**
 * Reads the first sheet of an .xlsx or .csv file into rows keyed by column header.
 *
 * The file is only read in memory: it is never kept on the server (spec § 25.2).
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * @return array{headers: list<string>, rows: list<array{line: int, cells: array<string, string>}>}
     *
     * @throws RuntimeException when the file cannot be read
     */
    public function read(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        try {
            $reader = match ($extension) {
                'xlsx' => new Xlsx(),
                'csv' => self::csvReader(),
                default => throw new RuntimeException('import.unsupported_format'),
            };
            $reader->setReadDataOnly(false);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('import.unreadable');
        }

        $headers = [];
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $values = [];
            foreach ($row->getCellIterator() as $cell) {
                $values[] = self::cellText($cell);
            }
            if (implode('', $values) === '') {
                continue; // empty line
            }
            if ($headers === []) {
                $headers = array_map(static fn(string $h): string => trim($h), $values);
                continue;
            }
            $cells = [];
            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $cells[$header] = $values[$i] ?? '';
                }
            }
            $rows[] = ['line' => $row->getRowIndex(), 'cells' => $cells];
            if (\count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('import.too_many_rows');
            }
        }

        if ($headers === []) {
            throw new RuntimeException('import.empty');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function csvReader(): Csv
    {
        $reader = new Csv();
        $reader->setInputEncoding(Csv::GUESS_ENCODING);
        $reader->setDelimiter(null); // detected from the file (";" from French Excel, "," otherwise)

        return $reader;
    }

    /**
     * Text of a cell; dates become YYYY-MM-DD whatever their display format.
     */
    private static function cellText(Cell $cell): string
    {
        $value = $cell->getValue();
        if ($value === null) {
            return '';
        }
        if (\is_numeric($value) && Date::isDateTime($cell)) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }
        if (\is_float($value) && floor($value) === $value) {
            return (string) (int) $value;
        }
        $text = $cell->getCalculatedValue();

        return \is_scalar($text) ? trim((string) $text) : '';
    }
}
