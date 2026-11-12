<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Results;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Config\Variant;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Missing events (spec § 11.3): present children who have not done an event
 * yet, by workshop (large, to be read out on the loudspeaker) or by child.
 * Filters by workshop and by category.
 */
final class MissingEventsAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $view = Responder::query($request, 'view') === 'child' ? 'child' : 'workshop';
        $workshopFilter = Responder::query($request, 'workshop');
        $categoryFilter = Responder::query($request, 'category');

        $variants = $this->config->variants();
        $done = [];
        foreach ($this->attempts->currentByChild(array_map(static fn(Variant $v): string => $v->id(), $variants)) as $childId => $list) {
            foreach ($list as $attempt) {
                $done[$childId][$attempt->variantId] = true;
            }
        }

        $byWorkshop = [];
        $byChild = [];
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            if ($child->bib === null || !$child->isPresent()) {
                continue;
            }
            $category = $child->child->level . '-' . $child->child->gender;
            if ($categoryFilter !== '' && $category !== $categoryFilter) {
                continue;
            }
            $missing = [];
            foreach ($this->config->variantsForGroup($this->config->groupOf($child->child->level)) as $variant) {
                if ($workshopFilter !== '' && $variant->workshopId !== $workshopFilter) {
                    continue;
                }
                if (!isset($done[$child->id][$variant->id()])) {
                    $missing[] = $variant;
                    $byWorkshop[$variant->workshopId][] = $child;
                }
            }
            if ($missing !== []) {
                $byChild[] = ['child' => $child, 'missing' => $missing];
            }
        }

        $workshops = [];
        foreach ($this->config->workshops as $workshop) {
            $workshops[$workshop->id] = $workshop;
        }

        return $this->responder->render($response, 'results/missing.html.twig', [
            'view' => $view,
            'workshops' => $workshops,
            'categories' => $this->config->categories(),
            'workshop_filter' => $workshopFilter,
            'category_filter' => $categoryFilter,
            'by_workshop' => array_filter($byWorkshop, static fn(string $id): bool => isset($workshops[$id]), ARRAY_FILTER_USE_KEY),
            'by_child' => $byChild,
        ]);
    }
}
