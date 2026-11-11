<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Security;

/**
 * Actions subject to access control (spec § 3.2).
 */
enum Permission: string
{
    case ManageConfiguration = 'manage_configuration';
    case ChangePhase = 'change_phase';
    case ManageAccounts = 'manage_accounts';
    case ManageRegistrationWindow = 'manage_registration_window';
    case ManageWaitingList = 'manage_waiting_list';
    case ImportSpreadsheets = 'import_spreadsheets';
    case RestoreAndPurge = 'restore_and_purge';
    case EmailRegistrants = 'email_registrants';
    case ViewRegistrations = 'view_registrations';
    case EditRegistration = 'edit_registration';
    case AddOnSite = 'add_on_site';
    case Unregister = 'unregister';
    case CheckInOut = 'check_in_out';
    case EnterResultsAnyEvent = 'enter_results_any_event';
    case EnterResultsChosenEvent = 'enter_results_chosen_event';
    case ViewRemainingBibs = 'view_remaining_bibs';
    case ViewWorkshopHelp = 'view_workshop_help';
    case ViewMonitoring = 'view_monitoring';
    case ViewResultsAndRankings = 'view_results_and_rankings';
    case PrintDocuments = 'print_documents';
    case DriveCeremonyScreen = 'drive_ceremony_screen';
    case ApproveClaudeActions = 'approve_claude_actions';
    case ViewAuditLog = 'view_audit_log';
}
