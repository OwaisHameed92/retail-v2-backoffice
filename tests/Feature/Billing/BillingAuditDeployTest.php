<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Actions\ReconcileGoCardless;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * The billing audit fixes (BILL-3, BILL-4, BILL-7, BILL-9, BILL-10, UI-5) change nothing for existing businesses when
 * they are deployed: no payment recorded, no invoice made or changed, no licence date moved, no suspension lifted or
 * made. They act only on what happens afterwards (a new GoCardless event, a payment recorded, a confirm). Existing
 * businesses in the states the fixes are about are built as the old code left them; the deploy day's billing:run,
 * the GoCardless reconcile and the portal and admin pages then leave every billing row exactly as it was.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['billing.suspend_after_days' => 7]);
});

/**
 * Every billing row that matters to a customer, for comparing before and after.
 *
 * @return array<string, mixed>
 */
function deploySnapshot(): array
{
    $rows = fn (string $table, array $columns) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($row) => (array) $row)->all();

    return [
        'companies' => $rows('companies', ['id', 'status', 'suspended_at']),
        'accounts' => $rows('billing_accounts', ['id', 'billing_suspended_at', 'suspension_invoice_id', 'mandate_deadline_at']),
        'invoices' => $rows('invoices', ['id', 'status', 'total', 'balance', 'amount_paid', 'paid_at', 'overdue_at', 'suspension_triggered_at', 'licences_renewed_at']),
        'payments' => $rows('payments', ['id', 'amount', 'unallocated', 'reversed_at', 'idempotency_key']),
        'allocations' => $rows('payment_allocations', ['id', 'amount', 'released_at']),
        'licences' => $rows('licences', ['id', 'status', 'expires_at', 'trial_ends_at', 'suspended_at']),
        'gocardless' => $rows('gocardless_payments', ['id', 'status', 'payment_id']),
    ];
}

/**
 * A period invoice paid after its period under the old code: paid, its licences stamped as renewed, the tills still
 * paid only to 31 Oct (BILL-7's stuck state).
 */
function deployStuckLatePayer(object $test, string $name, string $code): Company
{
    $company = $test->payingTenant($name, 1, $code);
    $invoice = $test->issuedFor($company);
    $test->pay($company, $invoice->total, [$invoice->id => $invoice->total], PaymentMethod::BankTransfer);
    foreach ($test->licencesOf($company) as $licence) {
        $licence->forceFill(['expires_at' => BillingDates::endOfDay(BillingDates::date('2026-10-31'))])->save();
    }
    Invoice::withoutCompanyScope()->whereKey($invoice->id)->update(['paid_at' => CarbonImmutable::parse('2026-12-02 12:00')]);

    return $company;
}

test('GB: deploying the fixes changes no existing business (billing:run, reconcile and pages on deploy day)', function () {
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->atLondon('2026-10-24 10:00');

    // BILL-3's stuck state: confirmed, failed late, retried and collected, never recorded; suspended since 12 Nov.
    $dd = $this->directDebitTenant('DD Late Failure', 1, 'DDL');
    $this->setUpMandate($dd);
    $payment = $this->gc->collect($this->gc->lastSubscription()->id, '2026-11-01');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->atLondon('2026-11-04 09:00');
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Failed, 'late_failure_settled')->assertOk();
    $this->gc->setPaymentStatus($payment->id, PaymentStatus::Confirmed);
    GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->update(['status' => PaymentStatus::Confirmed->value]);

    // BILL-7's stuck state (UK paying by hand), a healthy business, a demo business.
    $this->atLondon('2026-10-24 10:00');
    $late = deployStuckLatePayer($this, 'Late Payer', 'LTP');
    $healthy = $this->payingTenant('Healthy Shop', 1, 'HLT', paidUntil: '2026-12-31');
    $demo = $this->payingTenant('DEMO – Setup + monthly', 1, 'DEM', paidUntil: '2026-12-31');
    $demo->forceFill(['is_demo' => true])->save();

    foreach (['2026-11-05', '2026-11-12', '2026-11-20'] as $day) {
        $this->runBillingOn($day);
    }
    expect($this->companyFresh($dd)->status)->toBe(CompanyStatus::Suspended);

    // Deploy day.
    $this->atLondon('2026-12-03 05:30');
    $before = deploySnapshot();

    app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());
    $this->runBillingOn('2026-12-03');
    foreach ([$dd, $late, $healthy] as $company) {
        $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertOk();
    }
    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.index'))->assertOk();
    $this->actingAs($this->admin(), 'admin')->get(route('admin.dashboard'))->assertOk();

    expect(deploySnapshot())->toBe($before)
        ->and(DB::table('payments')->whereNotNull('idempotency_key')->count())->toBe(0)
        ->and($this->companyFresh($dd)->status)->toBe(CompanyStatus::Suspended)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $late->id)->sole()->status)->toBe(InvoiceStatus::Paid);
});

test('PK: deploying the fixes changes no existing business (billing:run and pages on deploy day)', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Asia/Karachi'));

    $late = deployStuckLatePayer($this, 'Lahore Mart', 'LHR');
    $healthy = $this->payingTenant('Karachi Store', 1, 'KHI', paidUntil: '2026-12-31');

    // Deploy day.
    $this->travelTo(CarbonImmutable::parse('2026-12-03 05:30', 'Asia/Karachi'));
    $before = deploySnapshot();

    $this->travelTo(CarbonImmutable::parse('2026-12-03 06:00', 'Asia/Karachi'));
    $this->runBilling();
    foreach ([$late, $healthy] as $company) {
        $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertOk();
    }
    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.index'))->assertOk();

    expect(deploySnapshot())->toBe($before);
})->group('country-pk');
