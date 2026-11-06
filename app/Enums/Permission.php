<?php

namespace App\Enums;

/**
 * Everything a user can be allowed to do, at module level.
 *
 * This is the single catalog of abilities: routes, controllers, policies and the
 * frontend ask for a permission, never for a role. Which role holds which
 * permission lives only in {@see UserRole::permissions()}.
 *
 * Convention: `<module>.view` to read, `<module>.manage` to create and change. Whoever
 * can manage a module can also view it (enforced by a test).
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    // People
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case MemberReportsView = 'member_reports.view';
    case ChurchSettingsManage = 'church_settings.manage';
    case AssetsView = 'assets.view';
    case AssetsManage = 'assets.manage';

    // Treasury
    case OfferingsView = 'offerings.view';
    case OfferingsManage = 'offerings.manage';
    case ClosuresView = 'closures.view';
    case ClosuresManage = 'closures.manage';
    case AccountingView = 'accounting.view';
    case AccountingManage = 'accounting.manage';
    case CatalogsView = 'catalogs.view';
    case CatalogsManage = 'catalogs.manage';
    case ReceiptsAdminister = 'receipts.administer';

    // Pastoral: tithes are not church income, so only the pastor sees them.
    case TithesView = 'tithes.view';
    case TithesManage = 'tithes.manage';

    // Oversight
    case SealedLedgerView = 'sealed_ledger.view';
    case AuditsView = 'audits.view';
    case AuditsRecord = 'audits.record';
    case ActivityLogView = 'activity_log.view';

    // Administration
    case UsersManage = 'users.manage';
}
