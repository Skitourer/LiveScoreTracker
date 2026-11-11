<?php

declare(strict_types=1);

namespace LiveScoreTracker\Infrastructure\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Turns an HTML page (rendered from a Twig template) into a PDF with Dompdf.
 *
 * Remote resources and PHP in templates are disabled: the PDF only uses the
 * HTML and inline styles given, with the DejaVu fonts shipped with Dompdf
 * (they cover accents and most European languages).
 */
final class PdfRenderer
{
    public function __construct(private readonly string $tempDir) {}

    public function render(string $html, string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir($this->tempDir);
        $options->setDefaultPaperSize('a4');
        $options->setDefaultPaperOrientation($orientation);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
