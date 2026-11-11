<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Entry;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Registration\TextCleaner;
use LiveScoreTracker\Domain\Results\AttemptProgress;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Remaining bibs at a workshop (spec § 8.6): present children of the levels
 * concerned who have not finished the event, with the attempts already made.
 * Sorted by bib, with a search on bib or first name.
 */
final class RemainingBibsAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly EntryDesk $desk,
        private readonly RegistrationRepository $registrations,
        private readonly AttemptRepository $attempts,
    ) {}

    /**
     * @param array{workshop: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $workshop = $this->desk->workshop($args['workshop']) ?? throw new HttpNotFoundException($request);
        $query = TextCleaner::key(Responder::query($request, 'q'));
        $byChild = $this->attempts->currentByChild(array_map(static fn($v): string => $v->id(), $workshop->variants));

        $remaining = [];
        $done = 0;
        foreach ($this->registrations->search(['statuses' => [RegistrationStatus::Registered]]) as $child) {
            $variant = $this->desk->variantFor($workshop, $child->child->level);
            if ($variant === null || !$child->isPresent()) {
                continue;
            }
            $progress = new AttemptProgress($variant, $byChild[$child->id] ?? []);
            if ($progress->isFinished()) {
                ++$done;
                continue;
            }
            if ($query !== '' && (string) $child->bib !== $query && !str_starts_with(TextCleaner::key($child->child->firstName), $query)) {
                continue;
            }
            $remaining[] = ['child' => $child, 'variant' => $variant, 'used' => $progress->used()];
        }

        return $this->responder->render($response, 'entry/remaining.html.twig', [
            'workshop' => $workshop,
            'remaining' => $remaining,
            'done' => $done,
            'query' => Responder::query($request, 'q'),
            'reason' => $remaining === [] && $query !== '' ? $this->whyNotListed($workshop, $query, $byChild) : null,
        ]);
    }

    /**
     * Why a search finds no remaining child: for a bib, the precise reason
     * (unknown, other level, not checked in, already finished); otherwise "no match".
     *
     * @param array<int, list<\LiveScoreTracker\Domain\Results\StoredAttempt>> $byChild
     */
    private function whyNotListed(\LiveScoreTracker\Domain\Config\Workshop $workshop, string $query, array $byChild): string
    {
        if (!ctype_digit($query)) {
            return 'entry.remaining_no_match';
        }
        $resolved = $this->desk->resolve($workshop, (int) $query);
        if (!\is_array($resolved)) {
            return $resolved;
        }
        [$child, $variant] = $resolved;
        if (!$child->isPresent()) {
            return 'entry.remaining_not_present';
        }

        return (new AttemptProgress($variant, $byChild[$child->id] ?? []))->isFinished() ? 'entry.remaining_finished' : 'entry.remaining_no_match';
    }
}
