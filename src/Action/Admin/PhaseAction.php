<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ControlBoard;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Phases of the edition (spec § 4): preparation ⇄ competition running →
 * finished, and reopening. Closing goes through a page that lists what is
 * still pending (missing events, ties, results to check, late entries); the
 * admin confirms knowingly.
 */
final class PhaseAction
{
    /** Allowed changes: current phase => targets. */
    private const MOVES = [
        'preparation' => [Phase::Running],
        'running' => [Phase::Preparation, Phase::Finished],
        'finished' => [Phase::Running],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly AppState $appState,
        private readonly Settings $settings,
        private readonly ControlBoard $board,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $current = $this->appState->phase();

        if ($request->getMethod() === 'POST') {
            $target = Phase::tryFrom(Responder::field($request, 'phase'));
            $closing = $target === Phase::Finished;
            if ($closing && Responder::field($request, 'confirm') !== '1') {
                return $this->responder->redirect($response, '/admin/phase?close=1');
            }
            if ($target !== null && \in_array($target, self::MOVES[$current->value], true)) {
                $details = ['from' => $current->value, 'to' => $target->value];
                if ($closing) {
                    $details += $this->summary();
                }
                $this->appState->setPhase($target);
                $this->responder->audit('phase.changed', $details);
                $key = $current === Phase::Finished ? 'phase.reopened' : 'phase.changed_to_' . $target->value;
                $this->responder->flash('success', $key);
            }

            return $this->responder->redirect($response, '/admin/phase');
        }

        if ($current === Phase::Running && Responder::query($request, 'close') === '1') {
            return $this->responder->render($response, 'admin/phase-close.html.twig', [
                'missing' => $this->board->missing(),
                'ties' => $this->board->ties(true),
                'to_check' => \count($this->board->toCheck()),
                'late' => \count($this->board->lateEntries()),
            ]);
        }

        return $this->responder->render($response, 'admin/phase.html.twig', [
            'current' => $current,
            // Test sites: link to the fictitious data reset.
            'test_data' => !$this->settings->isProduction() && $this->responder->context->can(Permission::RestoreAndPurge),
        ]);
    }

    /**
     * What was still pending when the admin closed, kept in the audit log.
     *
     * @return array<string, int>
     */
    private function summary(): array
    {
        $ties = 0;
        foreach ($this->board->ties(true) as $category) {
            $ties += \count($category['unresolved']);
        }

        return [
            'missing_children' => \count($this->board->missing()),
            'unresolved_ties' => $ties,
            'to_check' => \count($this->board->toCheck()),
        ];
    }
}
