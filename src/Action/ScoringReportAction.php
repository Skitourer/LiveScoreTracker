<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Demo\FictitiousCompetition;
use LiveScoreTracker\Demo\ScoringReport;
use LiveScoreTracker\Domain\Config\InvalidConfig;
use LiveScoreTracker\Infrastructure\Config\ConfigRepository;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

/**
 * Scoring report on fictitious data: the testable result of step 1 (spec § 23).
 *
 * Not available in production.
 */
final class ScoringReportAction
{
    private const DEFAULT_SEED = 2027;

    public function __construct(
        private readonly Twig $twig,
        private readonly Settings $settings,
        private readonly ConfigRepository $configRepository,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->settings->isProduction()) {
            throw new HttpNotFoundException($request);
        }

        try {
            $config = $this->configRepository->get();
        } catch (InvalidConfig $exception) {
            return $this->twig->render($response->withStatus(500), 'demo/config-errors.html.twig', ['errors' => $exception->errors]);
        }

        $query = $request->getQueryParams();
        $seed = isset($query['seed']) && \is_string($query['seed']) && ctype_digit($query['seed'])
            ? min((int) $query['seed'], 1_000_000)
            : self::DEFAULT_SEED;
        $closed = ($query['state'] ?? 'closed') !== 'running';

        $categories = $config->categories();
        $category = $categories[0];
        foreach ($categories as $candidate) {
            if ($candidate->id() === ($query['category'] ?? null)) {
                $category = $candidate;
            }
        }

        $competition = new FictitiousCompetition($config, $seed);
        $report = (new ScoringReport($config, $competition))->forCategory($category, $closed);

        return $this->twig->render($response, 'demo/report.html.twig', [
            'report' => $report,
            'categories' => $categories,
            'seed' => $seed,
            'closed' => $closed,
            'childCount' => \count($competition->children),
            'scoring' => $config->scoring,
        ]);
    }
}
