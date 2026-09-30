<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Tenant portal abilities. Checked with CurrentCompany::can() or the `company.can:<ability>` route middleware.
 */
enum Ability: string
{
    case DashboardView = 'dashboard.view';
    case SalesView = 'sales.view';
    case CatalogueView = 'catalogue.view';
    case CatalogueManage = 'catalogue.manage';
    case PricesManage = 'prices.manage';
    case PromotionsManage = 'promotions.manage';
    case StockView = 'stock.view';
    case StockManage = 'stock.manage';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case ReportsView = 'reports.view';
    case SettingsManage = 'settings.manage';
    case UsersManage = 'users.manage';
    case BillingView = 'billing.view';

    /** Review and settle sync conflicts (a shop's change the portal kept out). Owner and manager. */
    case SyncManage = 'sync.manage';

    /** Set up or change how the business pays (Direct Debit). Owner only. */
    case BillingManage = 'billing.manage';

    /** Till staff, their PINs and fobs, and what each till role may do (module 4.5). Owner and manager. */
    case StaffManage = 'staff.manage';

    /** The business's suppliers, sent to every till (module 4.5). Owner and manager. */
    case SuppliersManage = 'suppliers.manage';
}
