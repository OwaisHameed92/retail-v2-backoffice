<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/*
 * Every billing route: [route name, method, route params (fixture keys), "view" | "manage", request data].
 * Manage routes are listed in an order that works on one fixture ("Apply credit" uses the £5.00 credit first).
 */
$billingRoutes = [
    ['admin.billing.index', 'get', [], 'view', []],
    ['admin.billing.invoices.index', 'get', [], 'view', []],
    ['admin.billing.invoices.show', 'get', ['invoice' => 'issued1'], 'view', []],
    ['admin.billing.invoices.pdf', 'get', ['invoice' => 'issued1'], 'view', []],
    ['admin.billing.payments.index', 'get', [], 'view', []],
    ['admin.billing.payments.show', 'get', ['payment' => 'payment'], 'view', []],
    ['admin.billing.tenants.apply-credit', 'post', ['company' => 'company'], 'manage', []],
    ['admin.billing.tenants.invoice-preview', 'get', ['company' => 'company'], 'manage', []],
    ['admin.billing.tenants.open-invoices', 'get', ['company' => 'company'], 'manage', []],
    ['admin.billing.invoices.update', 'put', ['invoice' => 'draft1'], 'manage', ['notes' => 'Edited', 'lines' => [['description' => 'Till 1', 'quantity' => '1', 'unit_price' => '20.00']]]],
    ['admin.billing.invoices.destroy', 'delete', ['invoice' => 'draft2'], 'manage', []],
    ['admin.billing.invoices.issue', 'post', ['invoice' => 'draft3'], 'manage', []],
    ['admin.billing.invoices.send', 'post', ['invoice' => 'issued1'], 'manage', []],
    ['admin.billing.invoices.void', 'post', ['invoice' => 'issued2'], 'manage', ['reason' => 'Raised twice']],
    ['admin.billing.invoices.credit', 'post', ['invoice' => 'issued3'], 'manage', ['amount' => '5.00', 'reason' => 'Goodwill']],
    ['admin.billing.tenants.invoices.store', 'post', ['company' => 'company'], 'manage', ['allow_overlap' => true, 'notes' => 'Extra till']],
    ['admin.billing.tenants.payments.store', 'post', ['company' => 'company'], 'manage', ['method' => 'cash', 'amount' => '90.00', 'received_on' => '{today}', 'allocation' => 'auto']],
    ['admin.billing.tenants.settings', 'put', ['company' => 'company'], 'manage', ['billing_name' => 'Khan Holdings', 'billing_address' => '1 High St', 'emails' => ['accounts@khan.test'], 'cycle' => 'monthly', 'payment_terms_days' => 14, 'vat_applies' => true]],
    // Direct Debit (module 1.12), last: the setup fee takes the next invoice number.
    ['admin.billing.tenants.direct-debit.settings', 'put', ['company' => 'company'], 'manage', ['billing_mode' => 'directDebit', 'setup_fee_override' => '100.00', 'setup_fee_method' => 'manual', 'setup_fee_instalments' => 1]],
    ['admin.billing.tenants.direct-debit.setup-email', 'post', ['company' => 'company'], 'manage', []],
    ['admin.billing.tenants.direct-debit.setup-fee', 'post', ['company' => 'company'], 'manage', []],
    ['admin.billing.tenants.direct-debit.sync', 'post', ['company' => 'company'], 'manage', []],
    ['admin.billing.tenants.direct-debit.subscription', 'post', ['company' => 'company', 'action' => 'pause'], 'manage', []],
    // Module 1.13: pricing override, and the upfront payment (£0, on a second business with nothing invoiced).
    ['admin.billing.tenants.pricing', 'put', ['company' => 'company'], 'manage', ['pricing_mode' => 'perBranch', 'price_monthly' => '40.00', 'price_yearly' => '']],
    ['admin.billing.tenants.upfront', 'post', ['company' => 'fresh'], 'manage', ['upfront_amount' => '0', 'upfront_method' => 'cash']],
];

/**
 * A tenant with three drafts, three issued invoices (£30.00 each) and a £5.00 payment kept as credit.
 *
 * @return array<string, string>
 */
$billingFixture = function (object $test): array {
    $company = $test->payingTenant('Khan '.uniqid(), 1, 'KHN');
    $drafts = [];
    $issued = [];

    foreach (range(1, 3) as $i) {
        $issued[$i] = $test->issuedFor($company, new NewInvoice(allowOverlap: true))->id;
    }

    foreach (range(1, 3) as $i) {
        $drafts[$i] = $test->draftFor($company, new NewInvoice(allowOverlap: true))->id;
    }

    $payment = $test->pay($company, '5.00', [])->payment;

    // Direct Debit: a subscription still collecting while the mandate is suspended by the payer (so both "send
    // setup email" and "pause" apply).
    $subscription = $test->gc->createSubscription('MD000900', 3000, 'monthly', '2026-11-01', 'Test', [], 'fixture-'.$company->id);
    $account = $test->billingAccountOf($company);
    $account->forceFill(['gc_mandate_id' => 'MD000900', 'gc_mandate_status' => 'suspendedByPayer', 'gc_subscription_id' => $subscription->id, 'gc_subscription_status' => 'active', 'gc_subscription_amount' => '30.00', 'gc_subscription_cycle' => 'monthly'])->save();

    return [
        'company' => $company->id,
        'draft1' => $drafts[1], 'draft2' => $drafts[2], 'draft3' => $drafts[3],
        'issued1' => $issued[1], 'issued2' => $issued[2], 'issued3' => $issued[3],
        'payment' => $payment->id,
        'pause' => 'pause',
        'fresh' => $test->payingTenant('Fresh '.uniqid(), 1, 'FRS')->id,
    ];
};

$billingRequest = function (object $test, array $route, array $fixture, bool $json = false) {
    [$name, $method, $params, , $data] = $route;
    $url = route($name, array_map(fn (string $key) => $fixture[$key] ?? $key, $params));
    array_walk_recursive($data, function (mixed &$value) {
        $value = $value === '{today}' ? CarbonImmutable::now()->setTimezone('Europe/London')->format('Y-m-d') : $value;
    });

    return $test->{$method.($json ? 'Json' : '')}($url, $method === 'get' ? [] : $data);
};

test('the table covers every billing route', function () use ($billingRoutes) {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'admin/billing'))
        ->map(fn (RoutingRoute $route) => $route->getName())
        ->sort()->values()->all();

    expect($registered)->toHaveCount(25)
        ->and(collect($billingRoutes)->pluck(0)->sort()->values()->all())->toBe($registered);
});

test('guests are sent to the admin login, or get 401 as JSON', function () use ($billingRoutes, $billingFixture, $billingRequest) {
    $fixture = $billingFixture($this);

    foreach ($billingRoutes as $route) {
        $billingRequest($this, $route, $fixture)->assertRedirect(route('admin.login'));
        $billingRequest($this, $route, $fixture, json: true)->assertUnauthorized();
    }
});

test('customer users cannot reach billing admin routes', function () use ($billingRoutes, $billingFixture, $billingRequest) {
    $fixture = $billingFixture($this);
    $customer = $this->addMember(Company::query()->findOrFail($fixture['company']), CompanyRole::Owner);

    foreach ($billingRoutes as $route) {
        $this->actingAs($customer);
        $billingRequest($this, $route, $fixture)->assertRedirect(route('admin.login'));
    }

    expect(Invoice::withoutCompanyScope()->count())->toBe(6);
});

test('sales and support cannot read or change billing (owner and accounts only)', function (AdminRole $role) use ($billingRoutes, $billingFixture, $billingRequest) {
    expect($role->can(AdminRole::TENANTS_VIEW))->toBeTrue()->and($role->can(AdminRole::BILLING_MANAGE))->toBeFalse();

    $fixture = $billingFixture($this);
    $admin = $this->admin($role);

    foreach ($billingRoutes as $route) {
        $response = $billingRequest($this->actingAs($admin, 'admin'), $route, $fixture);

        expect($response->status())->toBe(403, "{$role->value} {$route[1]} {$route[0]} gave {$response->status()}");
    }

    expect(Invoice::withoutCompanyScope()->count())->toBe(6)
        ->and(Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Draft->value)->count())->toBe(3)
        ->and(Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Void->value)->count())->toBe(0)
        ->and(Payment::withoutCompanyScope()->count())->toBe(1)
        ->and(CreditNote::withoutCompanyScope()->count())->toBe(0)
        ->and($this->fresh(Invoice::withoutCompanyScope()->findOrFail($fixture['issued1']))->sent_count)->toBe(1)
        ->and($this->billingAccountOf(Company::query()->findOrFail($fixture['company']))->billing_name)->toBeNull()
        ->and($this->billingAccountOf(Company::query()->findOrFail($fixture['company']))->billing_mode->value)->toBe('upfrontCash')
        ->and($this->gc->calls)->not->toContain('pauseSubscription');
})->with([AdminRole::Sales, AdminRole::Support]);

test('accounts and owner admins can use every billing route', function (AdminRole $role) use ($billingRoutes, $billingFixture, $billingRequest) {
    expect($role->can(AdminRole::BILLING_MANAGE))->toBeTrue();

    $fixture = $billingFixture($this);
    $admin = $this->admin($role);

    foreach ($billingRoutes as $route) {
        $response = $billingRequest($this->actingAs($admin, 'admin'), $route, $fixture);

        expect($response->status())->toBeLessThan(400, "{$role->value} {$route[1]} {$route[0]} gave {$response->status()}");
        $response->assertSessionHasNoErrors();
    }

    $invoice = fn (string $key) => Invoice::withoutCompanyScope()->find($fixture[$key]);
    $company = Company::query()->findOrFail($fixture['company']);

    expect($invoice('draft1')->total)->toBe('24.00')
        ->and($invoice('draft1')->notes)->toBe('Edited')
        ->and($invoice('draft2'))->toBeNull()
        ->and($invoice('draft3')->number)->toBe('INV-000004')
        ->and($invoice('issued1')->sent_count)->toBe(2)
        ->and($invoice('issued2')->status)->toBe(InvoiceStatus::Void)
        ->and(CreditNote::withoutCompanyScope()->sole()->invoice_id)->toBe($fixture['issued3'])
        ->and(Payment::withoutCompanyScope()->count())->toBe(2)
        ->and(Payment::withoutCompanyScope()->where('amount', '90.00')->sole()->received_by_admin_id)->toBe((string) $admin->id)
        ->and(Invoice::withoutCompanyScope()->where('notes', 'Extra till')->sole()->created_by_admin_id)->toBe((string) $admin->id)
        ->and($this->billingAccountOf($company)->billing_name)->toBe('Khan Holdings')
        ->and($this->billingAccountOf($company)->payment_terms_days)->toBe(14)
        ->and($this->billingAccountOf($company)->billing_mode->value)->toBe('directDebit')
        ->and($this->billingAccountOf($company)->gc_setup_sent_at)->not->toBeNull()
        ->and($this->billingAccountOf($company)->gc_subscription_status?->value)->toBe('paused')
        ->and(Invoice::withoutCompanyScope()->where('kind', 'setupFee')->sole()->total)->toBe('120.00')
        ->and($this->billingAccountOf($company)->pricing_mode_override?->value)->toBe('perBranch')
        ->and($this->billingAccountOf(Company::query()->findOrFail($fixture['fresh']))->upfront_amount)->toBe('0.00');
})->with([AdminRole::Accounts, AdminRole::Owner]);

test('the JSON helpers answer with the preview and the open invoices', function () {
    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company);
    $this->actingAs($this->admin(AdminRole::Accounts), 'admin');

    $this->getJson(route('admin.billing.tenants.open-invoices', $company))
        ->assertOk()
        ->assertJsonCount(1, 'invoices')
        ->assertJsonPath('invoices.0.id', $invoice->id)
        ->assertJsonPath('invoices.0.number', 'INV-000001')
        ->assertJsonPath('invoices.0.balance', '60.00')
        ->assertJsonPath('invoices.0.balanceLabel', '£60.00');

    $this->getJson(route('admin.billing.tenants.invoice-preview', ['company' => $company, 'period_start' => '2026-12-01', 'cycle' => 'yearly']))
        ->assertOk()
        ->assertJsonPath('periodStart', '2026-12-01')
        ->assertJsonPath('periodEnd', '2027-11-30')
        ->assertJsonPath('total', '£600.00')
        ->assertJsonPath('overlaps', null);
});

test('the billing overview page', function () {
    $a = $this->payingTenant('Alpha Stores', 3, 'ALP');
    $this->issuedFor($a); // £90.00 due 31 Oct
    $this->pay($a, '40.00');
    $this->draftFor($this->payingTenant('Bravo Mart', 1, 'BRV')); // £30.00 draft
    $this->atLondon('2026-10-26 10:00');

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.billing.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/overview')
            ->where('stats.cashDue', ['count' => 1, 'amount' => '£50.00'])
            ->where('stats.overdue', ['count' => 0, 'amount' => '£0.00'])
            ->where('stats.dueThisWeek', ['count' => 1, 'amount' => '£50.00'])
            ->where('stats.collectedThisMonth', ['count' => 1, 'amount' => '£40.00'])
            ->where('stats.drafts', ['count' => 1, 'amount' => '£30.00'])
            ->where('stats.creditHeld', '£0.00')
            ->has('overdue', 0)
            ->has('dueSoon', 1)
            ->where('dueSoon.0.number', 'INV-000001')
            ->has('drafts', 1)
            ->has('recentPayments', 1)
            ->where('recentPayments.0.amount', '£40.00')
            ->has('suspended', 0)
            ->where('settings.suspendAfterDays', 14)
            ->where('canManage', true));
});

test('the invoice list, with filters and totals', function () {
    $a = $this->payingTenant('Alpha Stores', 3, 'ALP');
    $b = $this->payingTenant('Bravo Mart', 1, 'BRV');
    $this->issuedFor($a); // INV-000001 £90
    $this->issuedFor($b); // INV-000002 £30
    $this->draftFor($b, new NewInvoice(allowOverlap: true));
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/invoices/index')
            ->has('invoices.data', 3)
            ->where('invoices.meta.total', 3)
            ->where('totals', ['count' => 3, 'total' => '£150.00', 'balance' => '£150.00'])
            ->where('counts', ['draft' => 1, 'issued' => 2])
            ->has('statuses', 6)
            ->where('canManage', true));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.index', ['status' => 'open', 'company' => $b->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/invoices/index')
            ->has('invoices.data', 1)
            ->where('invoices.data.0.number', 'INV-000002')
            ->where('filters.company', ['id' => $b->id, 'name' => 'Bravo Mart'])
            ->where('totals.total', '£30.00'));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.index', ['search' => 'alpha']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)->where('invoices.data.0.company.name', 'Alpha Stores'));
});

test('the invoice page shows the document, payments and what the admin may do', function () {
    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company);
    $this->pay($company, '20.00');

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->get(route('admin.billing.invoices.show', $invoice->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/invoices/show')
            ->where('invoice.number', 'INV-000001')
            ->where('invoice.status', 'partiallyPaid')
            ->where('invoice.total', '£60.00')
            ->where('invoice.balance', '£40.00')
            ->has('invoice.lines', 2)
            ->has('invoice.payments', 1)
            ->where('invoice.document.number', 'INV-000001')
            ->where('invoice.can.manage', true)
            ->has('activity'));

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.billing.invoices.show', $invoice->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invoice.can.void', true)
            ->where('invoice.can.credit', true)
            ->where('invoice.can.edit', false)
            ->where('invoice.maxCredit', '40.00'));
});

test('the payment list and payment page', function () {
    $company = $this->payingTenant(tills: 1);
    $this->issuedFor($company);
    $payment = $this->pay($company, '50.00')->payment;
    $this->pay($this->payingTenant('Bravo Mart', 1, 'BRV'), '12.50', null, PaymentMethod::BankTransfer);
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->get(route('admin.billing.payments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/payments/index')
            ->has('payments.data', 2)
            ->where('totals', ['count' => 2, 'amount' => '£62.50'])
            ->has('manualMethods', 3)
            ->where('canManage', true));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.payments.index', ['method' => 'bankTransfer']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payments.data', 1)->where('payments.data.0.amount', '£12.50'));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.payments.show', $payment->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/payments/show')
            ->where('payment.number', 'PAY-000001')
            ->where('payment.amount', '£50.00')
            ->where('payment.unallocated', '£20.00')
            ->has('payment.allocations', 1)
            ->where('payment.allocations.0.invoiceNumber', 'INV-000001')
            ->has('activity', 1));
});

test('the tenant page carries the billing tab for owner and accounts only', function () {
    $company = $this->payingTenant(tills: 2);
    $this->issuedFor($company);
    $this->pay($company, '70.00');

    foreach ([AdminRole::Sales, AdminRole::Support] as $role) {
        $this->actingAs($this->admin($role), 'admin')->get(route('admin.tenants.show', $company))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/show')->where('billing', null));
    }

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get(route('admin.tenants.show', $company))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/show')
            ->where('billing.summary.balance', '£0.00')
            ->where('billing.summary.credit', '£10.00')
            ->where('billing.summary.nextPeriod.start', '2026-12-01')
            ->has('billing.invoices.data', 1)
            ->has('billing.payments.data', 1)
            ->where('billing.settings.recipientsAreOwners', true)
            ->where('billing.canManage', true));
});

test('unknown invoice and payment ids are 404s', function () {
    $this->actingAs($this->admin(), 'admin');

    $this->get(route('admin.billing.invoices.show', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    $this->get(route('admin.billing.payments.show', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    $this->post(route('admin.billing.invoices.issue', '01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
    $this->get('/admin/billing/invoices/not-a-ulid')->assertNotFound();
});
