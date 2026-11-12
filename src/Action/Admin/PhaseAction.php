<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Admin;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Switches between "preparation" and "competition running" (spec § 4).
 *
 * Closing the competition, with its blocking checks, comes with step 8.
 */
final class PhaseAction
{
    private const SWITCHABLE = [Phase::Preparation, Phase::Running];

    public function __construct(
        private readonly Responder $responder,
        private readonly AppState $appState,
        private readonly Settings $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $current = $this->appState->phase();

        if ($request->getMethod() === 'POST') {
            $target = Phase::tryFrom(Responder::field($request, 'phase'));
            if ($target !== null && \in_array($target, self::SWITCHABLE, true) && $target !== $current) {
                $this->appState->setPhase($target);
                $this->responder->audit('phase.changed', ['from' => $current->value, 'to' => $target->value]);
                $this->responder->flash('success', 'phase.changed_to_' . $target->value);
            }

            return $this->responder->redirect($response, '/admin/phase');
        }

        return $this->responder->render($response, 'admin/phase.html.twig', [
            'current' => $current,
            // Test sites: link to the fictitious data reset.
            'test_data' => !$this->settings->isProduction() && $this->responder->context->can(Permission::RestoreAndPurge),
        ]);
    }
}
