<?php

// Super admin area. Loaded under the /admin prefix with the "web" middleware group and the
// "admin." route name prefix (see bootstrap/app.php).

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Admin\AccountSecurityController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\TwoFactorController;
use App\Http\Controllers\Admin\Billing\BillingOverviewController;
use App\Http\Controllers\Admin\Billing\DirectDebitController;
use App\Http\Controllers\Admin\Billing\InvoiceActionController;
use App\Http\Controllers\Admin\Billing\InvoiceController;
use App\Http\Controllers\Admin\Billing\PaymentController;
use App\Http\Controllers\Admin\Billing\TenantBillingController;
use App\Http\Controllers\Admin\Billing\TenantPricingController;
use App\Http\Controllers\Admin\Catalogue\ContributionController;
use App\Http\Controllers\Admin\Catalogue\MasterCatalogueController;
use App\Http\Controllers\Admin\Catalogue\MasterImportController;
use App\Http\Controllers\Admin\CloudLinkController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DataRequestController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailSettingsController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\HeldEmailController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\Leads\LeadActionController;
use App\Http\Controllers\Admin\Leads\LeadApprovalController;
use App\Http\Controllers\Admin\Leads\LeadController;
use App\Http\Controllers\Admin\Licences\BranchLicenceController;
use App\Http\Controllers\Admin\Licences\BranchSyncKeyController;
use App\Http\Controllers\Admin\Licences\LicenceActionController;
use App\Http\Controllers\Admin\Licences\LicenceAlertController;
use App\Http\Controllers\Admin\Licences\LicenceController;
use App\Http\Controllers\Admin\Licences\LicenceKeyController;
use App\Http\Controllers\Admin\Licences\LicenceKeyEmailController;
use App\Http\Controllers\Admin\Licences\TenantLicenceController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanLifecycleController;
use App\Http\Controllers\Admin\TenantBranchController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantRegisterController;
use App\Http\Controllers\Admin\TenantStatusController;
use App\Http\Controllers\Admin\TenantUserController;
use App\Http\Controllers\Admin\TillHealthController;
use App\Http\Controllers\Admin\TradingController;
use App\Http\Middleware\AdminIsActive;
use App\Http\Middleware\BlockAdminWhileImpersonating;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ShareAdminInertiaData;
use Illuminate\Support\Facades\Route;

Route::get('login', [LoginController::class, 'create'])->name('login');
Route::post('login', [LoginController::class, 'store'])->name('login.store');

// Two-factor sign-in (required for every admin): the steps between the password and the admin area. Log out stays
// reachable from them. Everything in the main group below needs a session that passed (RequireTwoFactor).
Route::middleware(['auth:admin', AdminIsActive::class])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('two-factor')->name('two-factor.')->controller(TwoFactorController::class)->group(function () {
        Route::get('challenge', 'challenge')->name('challenge');
        Route::post('challenge', 'verify')->name('verify')->middleware('throttle:20,1');
        Route::get('setup', 'setup')->name('setup');
        Route::post('setup', 'confirm')->name('confirm')->middleware('throttle:20,1');
    });
});

// "Login as customer" ends here. Outside the main group: the admin area is blocked while impersonating (module 1.2).
Route::post('impersonation/stop', [ImpersonationController::class, 'destroy'])
    ->middleware(['auth:admin', AdminIsActive::class])->name('impersonation.stop');

Route::middleware(['auth:admin', AdminIsActive::class, BlockAdminWhileImpersonating::class, RequireTwoFactor::class.':admin', ShareAdminInertiaData::class])->group(function () {
    // The signed-in admin's own sign-in security: two-factor status and new recovery codes (JSON, shown once).
    Route::get('security', [AccountSecurityController::class, 'show'])->name('security');
    Route::post('security/recovery-codes', [AccountSecurityController::class, 'recoveryCodes'])->name('security.recovery-codes')->middleware('throttle:10,1');

    // Audit log across every business: owner and support (audit.view). CSV streams every matching entry.
    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index')->middleware('can:'.AdminRole::AUDIT_VIEW);
    Route::get('audit-log/export', [AuditLogController::class, 'export'])->name('audit-log.export')->middleware(['can:'.AdminRole::AUDIT_VIEW, 'throttle:10,1']);

    // Customer data requests (module 7.7) across every business: owner and support (audit.view). No customer details.
    Route::get('data-requests', [DataRequestController::class, 'index'])->name('data-requests.index')->middleware('can:'.AdminRole::AUDIT_VIEW);

    Route::get('/', DashboardController::class)->name('dashboard');

    // Starter catalogue and barcode lookup (gap #7): the platform-wide master catalogue, its CSV loads and the review
    // queue of barcodes collected from tills. Owner and support (catalogue.manage).
    Route::prefix('catalogue')->name('catalogue.')->middleware('can:'.AdminRole::CATALOGUE_MANAGE)->group(function () {
        Route::get('/', [MasterCatalogueController::class, 'index'])->name('index');
        Route::get('create', [MasterCatalogueController::class, 'create'])->name('create');
        Route::post('/', [MasterCatalogueController::class, 'store'])->name('store')->middleware('throttle:120,1');
        // The starter set is ~600 UK convenience products: 404 where the country profile hides it (P10, PK).
        Route::post('starter', [MasterCatalogueController::class, 'starter'])->name('starter')->middleware(['country.feature:ukStarterSet', 'throttle:5,1']);
        Route::get('imports', [MasterImportController::class, 'index'])->name('imports.index');
        Route::get('imports/template', [MasterImportController::class, 'template'])->name('imports.template');
        Route::post('imports', [MasterImportController::class, 'store'])->name('imports.store')->middleware('throttle:10,1');
        Route::get('contributions', [ContributionController::class, 'index'])->name('contributions.index');
        Route::post('contributions/{contribution}/approve', [ContributionController::class, 'approve'])->name('contributions.approve')->whereUlid('contribution')->middleware('throttle:120,1');
        Route::post('contributions/{contribution}/reject', [ContributionController::class, 'reject'])->name('contributions.reject')->whereUlid('contribution')->middleware('throttle:120,1');
        Route::get('{product}', [MasterCatalogueController::class, 'edit'])->name('edit')->whereUlid('product');
        Route::put('{product}', [MasterCatalogueController::class, 'update'])->name('update')->whereUlid('product')->middleware('throttle:120,1');
        Route::post('{product}/merge', [MasterCatalogueController::class, 'merge'])->name('merge')->whereUlid('product')->middleware('throttle:60,1');
    });

    // Module 3.2: the dashboard's Trading tab (shop sales across businesses). Owner, support and accounts.
    Route::get('trading', TradingController::class)->name('trading')->middleware('can:'.AdminRole::TRADING_VIEW);

    // Top-bar search (module 1.3): tenants and licences, JSON. GET for names and key endings; POST (body) is the only way to look up a full licence key.
    Route::match(['get', 'post'], 'search', AdminSearchController::class)->name('search')->middleware(['can:'.AdminRole::TENANTS_VIEW, 'throttle:120,1']);

    Route::prefix('admins')->name('admins.')->controller(AdminUserController::class)->group(function () {
        Route::get('/', 'index')->name('index')->can('viewAny', Admin::class);
        Route::get('create', 'create')->name('create')->can('create', Admin::class);
        Route::post('/', 'store')->name('store')->can('create', Admin::class);
        Route::get('{admin}/edit', 'edit')->name('edit')->can('update', 'admin');
        Route::put('{admin}', 'update')->name('update')->can('update', 'admin');
        Route::post('{admin}/deactivate', 'deactivate')->name('deactivate')->can('deactivate', 'admin');
        Route::post('{admin}/reactivate', 'reactivate')->name('reactivate')->can('reactivate', 'admin');
        Route::post('{admin}/two-factor/reset', [AccountSecurityController::class, 'reset'])->name('two-factor.reset')->can('resetTwoFactor', 'admin');
    });

    // Leads (module 1.6): tenants.view or leads.manage read, leads.manage works them, approving a trial also needs
    // tenants.manage (LeadPolicy). Archived leads stay reachable by id for "Restore".
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index')->can('viewAny', Lead::class);
        Route::get('create', [LeadController::class, 'create'])->name('create')->can('create', Lead::class);
        Route::post('/', [LeadController::class, 'store'])->name('store')->can('create', Lead::class);
        Route::get('{lead}', [LeadController::class, 'show'])->name('show')->can('view', 'lead')->withTrashed()->whereUlid('lead');

        Route::middleware('can:update,lead')->whereUlid('lead')->group(function () {
            Route::get('{lead}/edit', [LeadController::class, 'edit'])->name('edit');
            Route::put('{lead}', [LeadController::class, 'update'])->name('update');
            Route::post('{lead}/notes', [LeadActionController::class, 'note'])->name('notes.store');
            Route::post('{lead}/assign', [LeadActionController::class, 'assign'])->name('assign');
            Route::post('{lead}/follow-up', [LeadActionController::class, 'followUp'])->name('follow-up');
            Route::post('{lead}/contacted', [LeadActionController::class, 'contacted'])->name('contacted');
            Route::post('{lead}/reject', [LeadActionController::class, 'reject'])->name('reject');
            Route::post('{lead}/reopen', [LeadActionController::class, 'reopen'])->name('reopen');
            Route::delete('{lead}', [LeadActionController::class, 'archive'])->name('archive');
            Route::post('{lead}/restore', [LeadActionController::class, 'restore'])->name('restore')->withTrashed();
        });

        Route::post('{lead}/approve', [LeadApprovalController::class, 'store'])->name('approve')->can('approve', 'lead')->whereUlid('lead');
    });

    // Tenants (module 1.2): tenants.view reads, tenants.manage changes (CompanyPolicy).
    Route::prefix('tenants')->name('tenants.')->group(function () {
        Route::get('/', [TenantController::class, 'index'])->name('index')->can('viewAny', Company::class);
        Route::get('create', [TenantController::class, 'create'])->name('create')->can('create', Company::class);
        Route::post('/', [TenantController::class, 'store'])->name('store')->can('create', Company::class);
        Route::get('{company}', [TenantController::class, 'show'])->name('show')->can('view', 'company');

        Route::middleware('can:update,company')->group(function () {
            Route::get('{company}/edit', [TenantController::class, 'edit'])->name('edit');
            Route::put('{company}', [TenantController::class, 'update'])->name('update');

            Route::post('{company}/activate', [TenantStatusController::class, 'activate'])->name('activate');
            Route::post('{company}/suspend', [TenantStatusController::class, 'suspend'])->name('suspend');
            Route::post('{company}/unsuspend', [TenantStatusController::class, 'unsuspend'])->name('unsuspend');
            Route::post('{company}/cancel', [TenantStatusController::class, 'cancel'])->name('cancel');

            Route::post('{company}/branches', [TenantBranchController::class, 'store'])->name('branches.store');
            Route::put('{company}/branches/{branch}', [TenantBranchController::class, 'update'])->name('branches.update');
            Route::post('{company}/branches/{branch}/deactivate', [TenantBranchController::class, 'deactivate'])->name('branches.deactivate');
            Route::post('{company}/branches/{branch}/reactivate', [TenantBranchController::class, 'reactivate'])->name('branches.reactivate');
            Route::post('{company}/branches/{branch}/registers', [TenantRegisterController::class, 'store'])->name('registers.store');

            Route::put('{company}/registers/{register}', [TenantRegisterController::class, 'update'])->name('registers.update');
            Route::post('{company}/registers/{register}/main', [TenantRegisterController::class, 'main'])->name('registers.main');
            Route::post('{company}/registers/{register}/deactivate', [TenantRegisterController::class, 'deactivate'])->name('registers.deactivate');
            Route::post('{company}/registers/{register}/reactivate', [TenantRegisterController::class, 'reactivate'])->name('registers.reactivate');

            Route::post('{company}/users', [TenantUserController::class, 'store'])->name('users.store');
            Route::put('{company}/users/{user}', [TenantUserController::class, 'update'])->name('users.update')->whereNumber('user');
            Route::delete('{company}/users/{user}', [TenantUserController::class, 'destroy'])->name('users.destroy')->whereNumber('user');
            Route::post('{company}/users/{user}/password-link', [TenantUserController::class, 'sendPasswordLink'])->name('users.password-link')->whereNumber('user');
            Route::post('{company}/users/{user}/two-factor/reset', [TenantUserController::class, 'resetTwoFactor'])->name('users.two-factor.reset')->whereNumber('user');
        });

        Route::post('{company}/impersonate', [ImpersonationController::class, 'store'])->name('impersonate')->can('impersonate', 'company');

        // Licences of a tenant (module 1.3). Issuing answers with JSON (the keys exist only in that reply).
        Route::middleware('can:'.AdminRole::LICENCES_MANAGE)->group(function () {
            Route::post('{company}/licences/issue-missing', [TenantLicenceController::class, 'issueMissing'])->name('licences.issue-missing');
            Route::post('{company}/licences/renew', [TenantLicenceController::class, 'renewAll'])->name('licences.renew');
            Route::put('{company}/plan', [TenantLicenceController::class, 'changePlan'])->name('plan.update');
            Route::post('{company}/registers/{register}/licence', [TenantLicenceController::class, 'issueForRegister'])->name('registers.licence');
            // Licence form (module 1.11): a branch's licence settings and the company's branch limits.
            Route::put('{company}/branches/{branch}/licence', [BranchLicenceController::class, 'update'])->name('branches.licence');
            Route::delete('{company}/branches/{branch}/licence/features', [BranchLicenceController::class, 'usePlanFeatures'])->name('branches.licence.features.reset');
            Route::put('{company}/branch-limits', [BranchLicenceController::class, 'limits'])->name('branch-limits');
            // Module 2.1: a branch's sync key. Generate answers JSON (the key is shown once).
            Route::post('{company}/branches/{branch}/sync-key', [BranchSyncKeyController::class, 'generate'])->name('branches.sync-key.generate');
            Route::post('{company}/branches/{branch}/sync-key/rotate', [BranchSyncKeyController::class, 'rotate'])->name('branches.sync-key.rotate');
            Route::delete('{company}/branches/{branch}/sync-key', [BranchSyncKeyController::class, 'revoke'])->name('branches.sync-key.revoke');
        });
    });

    // Licences (module 1.3): tenants.view reads, licences.manage changes. Ids are looked up across companies in
    // the controllers (FindsLicences), never by route model binding.
    Route::prefix('licences')->name('licences.')->group(function () {
        Route::get('/', [LicenceController::class, 'index'])->name('index')->middleware('can:'.AdminRole::TENANTS_VIEW);
        // Keys arrive in the JSON body; allowed for whoever could create them (tenants.manage or licences.manage).
        Route::post('email-keys', LicenceKeyEmailController::class)->name('email-keys')->middleware('throttle:20,1');
        Route::get('{licence}', [LicenceController::class, 'show'])->name('show')->middleware('can:'.AdminRole::TENANTS_VIEW)->whereUlid('licence');

        Route::middleware('can:'.AdminRole::LICENCES_MANAGE)->whereUlid('licence')->group(function () {
            Route::put('{licence}/notes', [LicenceController::class, 'updateNotes'])->name('notes');
            Route::post('{licence}/renew', [LicenceActionController::class, 'renew'])->name('renew');
            Route::post('{licence}/plan', [LicenceActionController::class, 'changePlan'])->name('plan');
            Route::post('{licence}/release', [LicenceActionController::class, 'release'])->name('release');
            Route::post('{licence}/reissue', [LicenceActionController::class, 'reissue'])->name('reissue')->middleware('throttle:30,1');
            // Module 1.11: resend = reissue + email (plain keys are not stored); activate-by date of an unused key.
            Route::post('{licence}/resend', [LicenceKeyController::class, 'resend'])->name('resend')->middleware('throttle:30,1');
            Route::put('{licence}/activate-by', [LicenceKeyController::class, 'activateBy'])->name('activate-by');
            Route::post('{licence}/suspend', [LicenceActionController::class, 'suspend'])->name('suspend');
            Route::post('{licence}/unsuspend', [LicenceActionController::class, 'unsuspend'])->name('unsuspend');
            Route::post('{licence}/revoke', [LicenceActionController::class, 'revoke'])->name('revoke');
            // Licence API alerts (module 1.5).
            Route::post('{licence}/alerts/{alert}/resolve', [LicenceAlertController::class, 'resolve'])->name('alerts.resolve')->whereUlid('alert');
        });
    });

    // Till health (module 2.7): every till's online state, versions, sync and clock, across businesses. Read only.
    Route::get('till-health', [TillHealthController::class, 'index'])->name('till-health.index')->middleware('can:'.AdminRole::TENANTS_VIEW);

    // Cloud link (module 2.8): shops moving to the cloud and the local key register; clearing a record: licences.manage.
    Route::get('cloud-link', [CloudLinkController::class, 'index'])->name('cloud-link.index')->middleware('can:'.AdminRole::TENANTS_VIEW);
    Route::delete('cloud-link/local-keys/{localKey}', [CloudLinkController::class, 'destroy'])->name('cloud-link.local-keys.destroy')
        ->middleware('can:'.AdminRole::LICENCES_MANAGE)->whereUlid('localKey');

    // Emails (module 1.7): log, template previews and test sends. Owner and support (EmailLogPolicy).
    Route::prefix('emails')->name('emails.')->middleware('can:viewAny,'.EmailLog::class)->group(function () {
        Route::get('/', [EmailLogController::class, 'index'])->name('index');
        Route::get('templates', [EmailTemplateController::class, 'index'])->name('templates');
        Route::get('templates/{template}/preview', [EmailTemplateController::class, 'preview'])->name('templates.preview')->where('template', '[a-z0-9-]+');
        Route::post('templates/{template}/test', [EmailTemplateController::class, 'sendTest'])->name('templates.test')->where('template', '[a-z0-9-]+')
            ->middleware(['can:sendTest,'.EmailLog::class, 'throttle:10,1']);
    });

    // P11 (owner 2026-10-07): which tenant emails go by themselves, and the held ones (send, discard, preview; per
    // business: send all, welcome email, set-password link). Owner and accounts (billing.manage).
    Route::middleware('can:'.AdminRole::BILLING_MANAGE)->group(function () {
        Route::get('settings/emails', [EmailSettingsController::class, 'show'])->name('settings.emails');
        Route::put('settings/emails', [EmailSettingsController::class, 'update'])->name('settings.emails.update');
        Route::prefix('emails/held')->name('emails.held.')->whereUlid('held')->group(function () {
            Route::post('{held}/send', [HeldEmailController::class, 'send'])->name('send')->middleware('throttle:60,1');
            Route::post('{held}/discard', [HeldEmailController::class, 'discard'])->name('discard');
            Route::get('{held}/preview', [HeldEmailController::class, 'preview'])->name('preview');
        });
        Route::prefix('tenants/{company}/emails')->name('tenants.emails.')->group(function () {
            Route::post('send-held', [HeldEmailController::class, 'sendAll'])->name('send-held')->middleware('throttle:10,1');
            Route::post('welcome', [HeldEmailController::class, 'welcome'])->name('welcome')->middleware('throttle:10,1');
            Route::post('password-link', [HeldEmailController::class, 'passwordLink'])->name('password-link')->middleware('throttle:10,1');
        });
    });

    // Cash billing (module 1.8): owner and accounts only (billing.manage), reading included (owner decision
    // 2026-09-28). Invoice and payment ids are looked up across companies in the controllers
    // (FindsBillingRecords), never by route model binding.
    Route::prefix('billing')->name('billing.')->middleware('can:'.AdminRole::BILLING_MANAGE)->group(function () {
        Route::get('/', BillingOverviewController::class)->name('index');
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show')->whereUlid('invoice');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf')->whereUlid('invoice')->middleware('throttle:60,1');
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show')->whereUlid('payment');

        Route::whereUlid('invoice')->group(function () {
            Route::put('invoices/{invoice}', [InvoiceActionController::class, 'update'])->name('invoices.update');
            Route::delete('invoices/{invoice}', [InvoiceActionController::class, 'destroy'])->name('invoices.destroy');
            Route::post('invoices/{invoice}/issue', [InvoiceActionController::class, 'issue'])->name('invoices.issue');
            Route::post('invoices/{invoice}/send', [InvoiceActionController::class, 'send'])->name('invoices.send')->middleware('throttle:20,1');
            Route::post('invoices/{invoice}/void', [InvoiceActionController::class, 'void'])->name('invoices.void');
            Route::post('invoices/{invoice}/credit-notes', [InvoiceActionController::class, 'credit'])->name('invoices.credit');
        });

        Route::get('tenants/{company}/invoice-preview', [TenantBillingController::class, 'preview'])->name('tenants.invoice-preview');
        Route::get('tenants/{company}/open-invoices', [TenantBillingController::class, 'openInvoices'])->name('tenants.open-invoices');
        Route::post('tenants/{company}/invoices', [TenantBillingController::class, 'createInvoice'])->name('tenants.invoices.store');
        Route::post('tenants/{company}/payments', [TenantBillingController::class, 'recordPayment'])->name('tenants.payments.store');
        Route::put('tenants/{company}/settings', [TenantBillingController::class, 'settings'])->name('tenants.settings');
        Route::post('tenants/{company}/apply-credit', [TenantBillingController::class, 'applyCredit'])->name('tenants.apply-credit');
        // Module 1.13: the company's own pricing, and the upfront payment when it was not taken at onboarding.
        Route::put('tenants/{company}/pricing', [TenantPricingController::class, 'pricing'])->name('tenants.pricing');
        Route::post('tenants/{company}/upfront-payment', [TenantPricingController::class, 'upfront'])->name('tenants.upfront');

        // GoCardless Direct Debit (module 1.12).
        Route::prefix('tenants/{company}/direct-debit')->name('tenants.direct-debit.')->group(function () {
            Route::put('/', [DirectDebitController::class, 'settings'])->name('settings');
            Route::post('setup-email', [DirectDebitController::class, 'sendSetup'])->name('setup-email')->middleware(['billing.direct-debit', 'throttle:10,1']);
            Route::post('setup-fee', [DirectDebitController::class, 'chargeSetupFee'])->name('setup-fee');
            Route::post('payments/{payment}/retry', [DirectDebitController::class, 'retryPayment'])->name('retry')->middleware(['billing.direct-debit', 'throttle:10,1']);
            Route::post('subscription/sync', [DirectDebitController::class, 'syncSubscription'])->name('sync')->middleware('billing.direct-debit');
            Route::post('subscription/{action}', [DirectDebitController::class, 'subscription'])->name('subscription')->whereIn('action', ['pause', 'resume', 'cancel'])->middleware('billing.direct-debit');
        });
    });

    // Plans (module 1.1). Archived plans stay viewable, restorable and copyable.
    Route::prefix('plans')->name('plans.')->middleware('can:'.AdminRole::BILLING_MANAGE)->group(function () {
        Route::get('/', [PlanController::class, 'index'])->name('index');
        Route::get('create', [PlanController::class, 'create'])->name('create');
        Route::post('/', [PlanController::class, 'store'])->name('store');
        Route::get('{plan}', [PlanController::class, 'show'])->name('show')->withTrashed();
        Route::get('{plan}/edit', [PlanController::class, 'edit'])->name('edit');
        Route::put('{plan}', [PlanController::class, 'update'])->name('update');
        Route::delete('{plan}', [PlanLifecycleController::class, 'archive'])->name('archive');
        Route::post('{plan}/restore', [PlanLifecycleController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('{plan}/duplicate', [PlanLifecycleController::class, 'duplicate'])->name('duplicate')->withTrashed();
    });
});
