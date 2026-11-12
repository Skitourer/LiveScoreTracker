<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Security\Permission;
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
        ['dashboard.monitoring', null, Permission::ViewMonitoring],
        ['dashboard.phase_tile', '/admin/phase', Permission::ChangePhase],
        ['dashboard.accounts', '/admin/users', Permission::ManageAccounts],
        ['dashboard.referee_account', '/admin/referee', Permission::ManageAccounts],
        ['dashboard.audit_log', '/admin/audit', Permission::ViewAuditLog],
        ['dashboard.configuration', null, Permission::ManageConfiguration],
    ];

    public function __construct(private readonly Responder $responder) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tiles = [];
        foreach (self::TILES as [$label, $link, $permission]) {
            if ($this->responder->context->can($permission)) {
                $tiles[] = ['label' => $label, 'link' => $link];
            }
        }

        return $this->responder->render($response, 'dashboard.html.twig', ['tiles' => $tiles]);
    }
}
