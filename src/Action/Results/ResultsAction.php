<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Results;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\Standings;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Security\Phase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Results and rankings of one category (spec § 11.2, § 11.4): the overall
 * ranking with the score of each event, then each event with every attempt,
 * the retained result, score, rank and status. Recomputed on every display.
 */
final class ResultsAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CompetitionConfig $config,
        private readonly Standings $standings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $categories = $this->config->categories();
        $category = $categories[0];
        foreach ($categories as $candidate) {
            if ($candidate->id() === Responder::query($request, 'category')) {
                $category = $candidate;
            }
        }
        $closed = $this->responder->context->phase === Phase::Finished;

        return $this->responder->render($response, 'results/category.html.twig', [
            'categories' => $categories,
            'standings' => $this->standings->forCategory($category, $closed),
            'closed' => $closed,
        ]);
    }
}
