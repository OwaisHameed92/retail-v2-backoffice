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

    /** Shops and tills: shop details, read-only licences, till health, requests sent (module 4.7). Owner, manager, accountant. */
    case ShopsView = 'shops.view';

    /** Edit a shop's details and ask for more tills (module 4.7). Owner and manager (a one-shop manager: their shop). */
    case ShopsManage = 'shops.manage';

    /** Edit the business's own details (legal name, VAT number…) sent to every till (module 4.7). Owner only. */
    case BusinessManage = 'business.manage';

    /** Shifts, Z reports, cash office, card settlements, day locks and variance alerts, read only (module 5.4). Owner, manager, accountant. */
    case CashView = 'cash.view';

    /** Purchase orders, deliveries, supplier invoices, credits, returns, payments and statements (module 5.2). Owner, manager, accountant. */
    case PurchasingView = 'purchasing.view';

    /** Draft, send and cancel head-office orders for a shop (module 5.2). Owner and a manager of every shop. */
    case PurchasingManage = 'purchasing.manage';

    /** Clock events, timesheets, payroll CSV and the rota, read only (module 5.6). Owner, manager, accountant. */
    case StaffView = 'staff.view';

    /** Stock transfers between shops, their relay to the receiving till and discrepancies, read only (module 5.3). Owner, manager, accountant. */
    case TransfersView = 'transfers.view';

    /** Chart of accounts, journals, trial balance, P&L, balance sheet, expenses, VAT return helper, fixed assets; read only (module 5.5). Owner, manager, accountant. */
    case AccountsView = 'accounts.view';

    /** Age checks and refusals, incidents, training, diary checks, licences held, recalls and the exceptions report (module 5.7). Owner, manager, accountant. */
    case ComplianceView = 'compliance.view';

    /** Raise, edit and close product recalls sent to every till (module 5.7). Owner and a manager of every shop. */
    case ComplianceManage = 'compliance.manage';
}
