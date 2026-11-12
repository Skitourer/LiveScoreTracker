<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Documents;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\Documents;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Help\HelpPages;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Pdf\PdfRenderer;
use LiveScoreTracker\Infrastructure\Spreadsheet\ResultsExport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * The documents page and its downloads (spec § 14): rescue sheets, workshop
 * help sheets, podiums, full rankings (PDF and Excel) and detailed results.
 * Documents hold full names: every download is recorded in the audit log.
 */
final class DocumentsAction
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly Responder $responder,
        private readonly Documents $documents,
        private readonly ResultsExport $export,
        private readonly CompetitionConfig $config,
        private readonly HelpPages $help,
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly PdfRenderer $pdf,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array{name?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $closed = $this->responder->context->phase === Phase::Finished;
        $name = $args['name'] ?? null;
        if ($name === null) {
            return $this->responder->render($response, 'documents/index.html.twig', [
                'freshness' => $this->documents->rescueFreshness(),
                'closed' => $closed,
            ]);
        }

        $now = $this->clock->stamp();
        $common = ['edition' => $this->config->edition, 'printedAt' => $now, 'closed' => $closed];

        return match ($name) {
            'rescue-sheets.pdf' => $this->rescueSheets($response, $common, $now),
            'help-sheets.pdf' => $this->pdf($response, 'help_sheets', 'pdf/help-sheets.html.twig', $common + ['sheets' => $this->helpSheets()]),
            'podiums.pdf' => $this->pdf($response, 'podiums', 'pdf/podiums.html.twig', $common + ['podiums' => $this->documents->podiums($closed)]),
            'rankings.pdf' => $this->pdf($response, 'rankings', 'pdf/rankings.html.twig', $common + ['categories' => $this->documents->rankings($closed)], 'landscape'),
            'rankings.xlsx' => $this->xlsx($response, 'rankings', $this->export->rankings($closed)),
            'results.xlsx' => $this->xlsx($response, 'results', $this->export->detailed()),
            default => throw new HttpNotFoundException($request),
        };
    }

    /**
     * @param array<string, mixed> $common
     */
    private function rescueSheets(ResponseInterface $response, array $common, string $now): ResponseInterface
    {
        $response = $this->pdf($response, 'rescue_sheets', 'pdf/rescue-sheets.html.twig', $common + [
            'sheets' => $this->documents->rescueSheets(),
            'blankLines' => Documents::BLANK_LINES,
        ], 'landscape');
        $this->documents->markRescuePrinted($now);

        return $response;
    }

    /**
     * @return list<array{workshop: \LiveScoreTracker\Domain\Config\Workshop, help: ?string}>
     */
    private function helpSheets(): array
    {
        $sheets = [];
        foreach ($this->config->workshops as $workshop) {
            $sheets[] = [
                'workshop' => $workshop,
                'help' => $this->help->html($workshop, $this->translator->locale(), $this->config->edition->defaultLocale),
            ];
        }

        return $sheets;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pdf(ResponseInterface $response, string $document, string $template, array $data, string $orientation = 'portrait'): ResponseInterface
    {
        $html = $this->twig->fetch($template, $data + ['contacts' => $this->config->contacts]);
        $this->responder->audit('documents.downloaded', ['document' => $document]);
        $response->getBody()->write($this->pdf->render($html, $orientation));

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $this->fileName($document) . '.pdf"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function xlsx(ResponseInterface $response, string $document, string $content): ResponseInterface
    {
        $this->responder->audit('documents.downloaded', ['document' => $document . '_xlsx']);
        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', self::XLSX)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $this->fileName($document) . '.xlsx"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function fileName(string $document): string
    {
        return $this->translator->trans("documents.{$document}.file_name") . '-' . $this->config->edition->date->format('Y');
    }
}
