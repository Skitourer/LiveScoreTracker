<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Reception;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ReceptionList;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Pdf\PdfRenderer;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

/**
 * The printable reception list (spec § 6.1 and § 14): first name, full name,
 * bib and class of every registered child, sorted by first name. It holds
 * full names: the download is recorded in the audit log.
 */
final class ReceptionListAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
        private readonly CompetitionConfig $config,
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly PdfRenderer $pdf,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $children = $this->registrations->search(['statuses' => [RegistrationStatus::Registered]]);
        $html = $this->twig->fetch('pdf/reception-list.html.twig', [
            'groups' => ReceptionList::byFirstName($children, $this->translator->locale()),
            'count' => \count($children),
            'edition' => $this->config->edition,
            'printedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->responder->audit('reception.list_printed', ['count' => \count($children)]);
        $response->getBody()->write($this->pdf->render($html));

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . $this->translator->trans('reception_list.file_name') . '.pdf"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
