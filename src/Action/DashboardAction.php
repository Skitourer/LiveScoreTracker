<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Application\ControlBoard;
use LiveScoreTracker\Domain\Security\Permission;
use LiveScoreTracker\Domain\Security\Phase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Landing page after login: the tools available to the account's role.
 * Tools of later development steps are listed as "coming soon".
 */
final class DashboardAction
{
    /**
     * Tiles in display order: [translation key, link or null when not built yet, permission].
     */
    private const TILES = [
        ['dashboard.results_entry', '/entry', Permission::EnterResultsChosenEvent],
        ['dashboard.registrations', '/registrations', Permission::ViewRegistrations],
        ['dashboard.check_in', '/check-in', Permission::CheckInOut],
        ['dashboard.rankings', '/results', Permission::ViewResultsAndRankings],
        ['dashboard.missing_events', '/missing', Permission::ViewMonitoring],
        ['dashboard.checks', '/checks', Permission::ViewMonitoring],
        ['dashboard.monitoring', '/progress', Permission::ViewMonitoring],
        ['dashboard.phase_tile', '/admin/phase', Permission::ChangePhase],
        ['dashboard.accounts', '/admin/users', Permission::ManageAccounts],
        ['dashboard.referee_account', '/admin/referee', Permission::ManageAccounts],
        ['dashboard.audit_log', '/admin/audit', Permission::ViewAuditLog],
        ['dashboard.configuration', null, Permission::ManageConfiguration],
        ['dashboard.system_status', '/status', Permission::ManageConfiguration],
    ];

    public function __construct(
        private readonly Responder $responder,
        private readonly ControlBoard $board,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $context = $this->responder->context;
        $tiles = [];
        foreach (self::TILES as [$label, $link, $permission]) {
            if ($context->can($permission)) {
                $count = null;
                // Items waiting on the control board, outside the preparation phase.
                if ($link === '/checks' && $context->phase !== Phase::Preparation) {
                    $count = $this->board->pendingCount($context->phase === Phase::Finished);
                }
                $tiles[] = ['label' => $label, 'link' => $link, 'count' => $count];
            }
        }

        return $this->responder->render($response, 'dashboard.html.twig', ['tiles' => $tiles]);
    }
}
