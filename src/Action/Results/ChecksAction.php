<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Results;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ControlBoard;
use LiveScoreTracker\Application\EntryDesk;
use LiveScoreTracker\Application\EntrySync;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Clock;
use LiveScoreTracker\Infrastructure\Persistence\AttemptRepository;
use LiveScoreTracker\Infrastructure\Persistence\LateEntryRepository;
use LiveScoreTracker\Infrastructure\Persistence\TieDecisionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Control board (spec § 10): results "to check" with a link to the
 * correction and a "verified" button, ties to order by hand (admin), and
 * entries arrived after the closing, to accept or refuse.
 */
final class ChecksAction
{
    public function __construct(
        private readonly Responder $responder,
        private readonly ControlBoard $board,
        private readonly AttemptRepository $attempts,
        private readonly LateEntryRepository $lateEntries,
        private readonly TieDecisionRepository $tieDecisions,
        private readonly EntryDesk $desk,
        private readonly EntrySync $sync,
        private readonly Clock $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        if ($request->getMethod() === 'POST') {
            match (Responder::field($request, 'op')) {
                'verify' => $context->can(Permission::EnterResultsAnyEvent) && $this->verify((int) Responder::field($request, 'attempt')),
                'accept', 'refuse' => $context->can(Permission::EnterResultsAnyEvent)
                    && $this->decideLate((int) Responder::field($request, 'late'), Responder::field($request, 'op') === 'accept'),
                'tie' => $context->can(Permission::ChangePhase) && $this->orderTie($request),
                'tie_reset' => $context->can(Permission::ChangePhase) && $this->resetTie($request),
                default => false,
            };

            return $this->responder->redirect($response, '/checks');
        }

        $closed = $context->phase === Phase::Finished;

        return $this->responder->render($response, 'results/checks.html.twig', [
            'to_check' => $this->board->toCheck(),
            'ties' => $this->board->ties($closed),
            'late_entries' => $this->board->lateEntries(),
            'closed' => $closed,
            'can_verify' => $context->can(Permission::EnterResultsAnyEvent),
            'can_order_ties' => $context->can(Permission::ChangePhase),
            'can_decide_late' => $context->can(Permission::EnterResultsAnyEvent) && $context->phase !== Phase::Preparation,
        ]);
    }

    private function verify(int $attemptId): bool
    {
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null || !$this->attempts->markChecked($attempt, $this->by())) {
            return false;
        }
        $this->responder->audit('results.checked', ['bib' => $attempt->bib, 'event' => $attempt->variantId, 'attempt' => $attempt->id]);
        $this->responder->flash('success', 'checks.verified', ['bib' => $attempt->bib]);

        return true;
    }

    private function decideLate(int $id, bool $accept): bool
    {
        $entry = $this->lateEntries->findById($id);
        if ($entry === null || $entry->decision !== null || $this->responder->context->phase === Phase::Preparation) {
            return false;
        }
        $details = ['bib' => $entry->bib, 'event' => $entry->variantId, 'late_entry' => $entry->id];
        if (!$accept) {
            $this->lateEntries->decide($entry, LateEntryRepository::REFUSED, $this->by());
            $this->responder->audit('late_entry.refused', $details);
            $this->responder->flash('warning', 'checks.late_refused', ['bib' => $entry->bib]);

            return true;
        }
        $workshop = $this->desk->workshop($entry->workshopId);
        $outcome = $workshop === null
            ? ['status' => EntrySync::REJECTED, 'reason' => EntryDesk::OTHER_GROUP]
            : $this->sync->accept($workshop, $entry, $this->clock->now()->getTimestamp());
        if ($outcome['status'] !== EntrySync::SAVED) {
            $this->responder->flash('error', $outcome['reason'] ?? 'checks.late_failed', ['bib' => (string) $entry->bib]);

            return false;
        }
        $this->lateEntries->decide($entry, LateEntryRepository::ACCEPTED, $this->by());
        $this->responder->audit('late_entry.accepted', $details);
        $this->responder->flash('success', 'checks.late_accepted', ['bib' => $entry->bib]);

        return true;
    }

    /**
     * The admin orders an unbroken tie: one position per child, each position once.
     */
    private function orderTie(ServerRequestInterface $request): bool
    {
        $categoryId = Responder::field($request, 'category');
        $body = $request->getParsedBody();
        $positions = \is_array($body) && \is_array($body['position'] ?? null) ? $body['position'] : [];
        $ranked = [];
        foreach ($positions as $bib => $position) {
            if (\is_string($position) && ctype_digit($position)) {
                $ranked[(string) $bib] = (int) $position;
            }
        }
        $values = array_values($ranked);
        sort($values);
        if (\count($ranked) < 2 || $values !== range(1, \count($ranked)) || !$this->isUnresolvedTie($categoryId, array_keys($ranked))) {
            $this->responder->flash('error', 'checks.tie_invalid');

            return false;
        }
        asort($ranked);
        $order = array_map(strval(...), array_keys($ranked));
        $this->tieDecisions->decide($categoryId, $order, $this->by());
        $this->responder->audit('ties.decided', ['category' => $categoryId, 'order' => implode(' > ', $order)]);
        $this->responder->flash('success', 'checks.tie_decided');

        return true;
    }

    private function resetTie(ServerRequestInterface $request): bool
    {
        $categoryId = Responder::field($request, 'category');
        $bibs = array_values(array_filter(explode(',', Responder::field($request, 'bibs')), static fn(string $bib): bool => $bib !== ''));
        if (\count($bibs) < 2) {
            return false;
        }
        $this->tieDecisions->forget($categoryId, $bibs);
        $this->responder->audit('ties.reset', ['category' => $categoryId, 'bibs' => implode(', ', $bibs)]);
        $this->responder->flash('warning', 'checks.tie_reset');

        return true;
    }

    /**
     * @param list<int|string> $bibs
     */
    private function isUnresolvedTie(string $categoryId, array $bibs): bool
    {
        $wanted = TieDecisionRepository::setKey(array_map(strval(...), $bibs));
        foreach ($this->board->ties($this->responder->context->phase === Phase::Finished) as $category) {
            if ($category['category']->id() !== $categoryId) {
                continue;
            }
            foreach ($category['unresolved'] as $group) {
                if (TieDecisionRepository::setKey(array_map(static fn($line): string => $line->bib, $group)) === $wanted) {
                    return true;
                }
            }
        }

        return false;
    }

    private function by(): string
    {
        $context = $this->responder->context;

        return $context->refereeName() ?? $context->user->username ?? '?';
    }
}
