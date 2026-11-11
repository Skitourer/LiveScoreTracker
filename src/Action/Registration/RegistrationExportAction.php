<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Spreadsheet\RegistrationExport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Downloads the registrations list as an Excel file (spec § 5.9).
 * The file holds personal data: the download is recorded in the audit log.
 */
final class RegistrationExportAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly RegistrationRepository $registrations,
        private readonly RegistrationExport $export,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $list = $this->registrations->search();
        $this->responder->audit('registration.exported', ['count' => \count($list)]);
        $response->getBody()->write($this->export->xlsx($list));

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="registrations-' . gmdate('Ymd') . '.xlsx"')
            ->withHeader('Cache-Control', 'no-store');
    }
}
