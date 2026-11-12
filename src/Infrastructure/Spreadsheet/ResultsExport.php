<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Spreadsheet;

use LiveScoreTracker\Application\Documents;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Presentation\ResultFormatter;
use LiveScoreTracker\Presentation\TwigExtension;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel exports of the results (spec § 14): full rankings (one sheet per
 * category) and detailed results (every attempt, with its author and time).
 */
final class ResultsExport
{
    public function __construct(
        private readonly Documents $documents,
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
        private readonly Translator $translator,
        private readonly TwigExtension $labels,
        private readonly ResultFormatter $formatter,
    ) {}

    /**
     * Rankings by category: rank, bib, names, class, total, points per event, tie-break counts.
     */
    public function rankings(bool $closed): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        foreach ($this->documents->rankings($closed) as $category) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(self::sheetTitle($this->labels->categoryLabel($category['category'])));
            $header = [
                $this->t('documents.col.rank'), $this->t('documents.col.bib'), $this->t('registration.field.last_name'),
                $this->t('registration.field.first_name'), $this->t('registration.field.school'), $this->t('documents.col.total'),
            ];
            foreach ($category['events'] as $event) {
                $header[] = $this->labels->eventLabel($event['variant']);
            }
            $header[] = $this->t('documents.col.first_places');
            $header[] = $this->t('documents.col.top3_places');
            $rows = [$header];
            foreach ($category['ranking'] as $line) {
                $child = $category['children'][(int) $line->bib] ?? null;
                $row = [$line->rank, (int) $line->bib, $child?->child->lastName, $child?->child->firstName, $child?->child->school, $line->total];
                foreach ($category['events'] as $event) {
                    $row[] = $line->events[$event['variant']->id()]?->score;
                }
                $row[] = $line->firstPlaces;
                $row[] = $line->top3Places;
                $rows[] = $row;
            }
            self::fill($sheet, $rows);
        }
        if ($spreadsheet->getSheetCount() === 0) {
            $spreadsheet->createSheet();
        }

        return self::save($spreadsheet);
    }

    /**
     * Every current attempt (corrections replace the earlier versions), one line each.
     */
    public function detailed(): string
    {
        $variants = [];
        foreach ($this->config->variants() as $variant) {
            $variants[$variant->id()] = $variant;
        }
        $children = [];
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            $children[$child->id] = $child;
        }

        $rows = [[
            $this->t('documents.col.bib'), $this->t('registration.field.last_name'), $this->t('registration.field.first_name'),
            $this->t('registration.field.level'), $this->t('registration.field.gender'), $this->t('documents.col.event'),
            $this->t('documents.col.value'), $this->t('documents.col.modifiers'), $this->t('documents.col.result'),
            $this->t('documents.col.entered_at'), $this->t('documents.col.entered_by'), $this->t('documents.col.to_check'),
        ]];
        foreach ($this->attempts->currentByChild(array_keys($variants)) as $childId => $list) {
            $child = $children[$childId] ?? null;
            foreach ($list as $attempt) {
                $variant = $variants[$attempt->variantId] ?? null;
                if ($variant === null || !$child instanceof Registration) {
                    continue;
                }
                $result = $attempt->result();
                $rows[] = [
                    $attempt->bib,
                    $child->child->lastName,
                    $child->child->firstName,
                    $child->child->level,
                    $this->t("gender.{$child->child->gender}.one"),
                    $this->labels->eventLabel($variant),
                    $attempt->isFoul() ? $this->t('report.foul') : $this->formatter->format((int) $attempt->measured, $variant),
                    $attempt->modifierTotal !== 0 ? $attempt->modifierTotal : null,
                    $result === null ? $this->t('report.foul') : $this->formatter->format($result, $variant),
                    $this->labels->localTime($attempt->enteredAt),
                    $attempt->author(),
                    $attempt->isFlagged() && !$attempt->isChecked() ? $this->t('registration.yes') : '',
                ];
            }
        }
        usort($rows, static fn(array $a, array $b): int => \is_int($a[0]) && \is_int($b[0]) ? [$a[0], $a[5]] <=> [$b[0], $b[5]] : (\is_int($a[0]) ? 1 : -1));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sheetTitle($this->t('documents.results_title')));
        self::fill($sheet, $rows);

        return self::save($spreadsheet);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key);
    }

    /**
     * @param list<list<mixed>> $rows first row = header
     */
    private static function fill(Worksheet $sheet, array $rows): void
    {
        $sheet->fromArray($rows, null, 'A1', true);
        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }

    /**
     * Excel sheet names: 31 characters at most, without : \ / ? * [ ].
     */
    private static function sheetTitle(string $title): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $title), 0, 31);
    }

    private static function save(Spreadsheet $spreadsheet): string
    {
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
