<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Demo\ImportSample;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Downloads a spreadsheet of fictitious registrations to try the import.
 * Not available in production.
 */
final class ImportSampleAction
{
    public function __construct(
        private readonly ImportSample $sample,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->isProduction()) {
            throw new HttpNotFoundException($request);
        }
        $response->getBody()->write($this->sample->xlsx());

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="fictitious-registrations.xlsx"');
    }
}
