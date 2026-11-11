<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * The rights matrix of spec § 3.2, checked on the server for every action.
 */
final class AccessPolicy
{
    private const ORGANIZER = [
        Permission::ViewRegistrations,
        Permission::EditRegistration,
        Permission::AddOnSite,
        Permission::CheckInOut,
        Permission::EnterResultsAnyEvent,
        Permission::EnterResultsChosenEvent,
        Permission::ViewRemainingBibs,
        Permission::ViewWorkshopHelp,
        Permission::ViewMonitoring,
        Permission::ViewResultsAndRankings,
        Permission::PrintDocuments,
        Permission::DriveCeremonyScreen,
        Permission::ApproveClaudeActions,
    ];

    private const REFEREE = [
        Permission::EnterResultsChosenEvent,
        Permission::ViewRemainingBibs,
        Permission::ViewWorkshopHelp,
    ];

    public static function allows(Role $role, Permission $permission): bool
    {
        return match ($role) {
            Role::Admin => true,
            Role::Organizer => \in_array($permission, self::ORGANIZER, true),
            Role::Referee => \in_array($permission, self::REFEREE, true),
        };
    }

    /**
     * Whether an account with this role may be logged in during the given phase.
     * The shared referee account only works while the competition is running (spec § 3.2).
     */
    public static function roleActiveIn(Role $role, Phase $phase): bool
    {
        return $role !== Role::Referee || $phase === Phase::Running;
    }
}
