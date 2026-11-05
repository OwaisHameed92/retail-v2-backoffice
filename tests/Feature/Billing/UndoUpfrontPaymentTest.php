<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Actions\UndoUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * "Undo upfront payment (recorded by mistake)" (owner 2026-11-05): the onboarding recorded the setup fee as paid in
 * cash, the customer paid nothing and agreed to pay it in 3 monthly instalments.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-11-05 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** A business onboarded today (Standard plan, £25 a month) with the setup fee recorded as paid upfront. */
function undoOnboarded(object $test, string $name = 'Patel News', string $code = 'PTL', int $instalments = 1, PaymentMethod $method = PaymentMethod::Cash): Company
{
    $company = $test->trialTenant($name, 1, '2026-11-12 10:00', $code);
    $test->standardPlan()->forceFill(['setup_fee' => '950.00', 'price_monthly' => '25.00', 'price_yearly' => '0.00', 'billing_type' => null])->save();

    if ($instalments > 1) {
        $test->billingAccountOf($company)->forceFill(['setup_fee_instalments' => $instalments])->save();
    }

    app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment('950.00', $method));

    return $company->refresh();
}

/** @return list<Invoice> */
function undoSetupInvoices(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)
        ->orderBy('sequence')->get()->all();
}

/** What a till holds: dates and status (must not change). */
function undoLicenceSnapshot(object $test, Company $company): array
{
    return array_map(fn ($licence) => [
        'expires_at' => $test->licenceFresh($licence)->expires_at?->toIso8601String(),
        'trial_ends_at' => $test->licenceFresh($licence)->trial_ends_at?->toIso8601String(),
        'status' => $test->licenceFresh($licence)->status->value,
    ], $test->licencesOf($company));
}

function undoRefusal(Company $company, string $expected): void
{
    try {
        app(UndoUpfrontPayment::class)->handle($company, 'Customer did not pay');
        test()->fail('The undo was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['upfront'][0] ?? '')->toContain($expected);
    }
}

test('the real case: undo the cash recorded by mistake, invoice the setup fee in 3 instalments, record the first', function () {
    $company = undoOnboarded($this);
    $other = undoOnboarded($this, 'Khan Mini Mart', 'KHN');
    $admin = $this->admin(AdminRole::Accounts);

    [$invoice] = undoSetupInvoices($company);
    $payment = Payment::withoutCompanyScope()->where('company_id', $company->id)->sole();
    $account = $this->billingAccountOf($company);
    expect($invoice->total)->toBe('1140.00')->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->method)->toBe(PaymentMethod::Cash)
        ->and($payment->notes)->toBe('Setup fee (upfront) paid by cash')
        ->and($account->upfront_amount)->toBe('1140.00')
        ->and(SetupFeeState::for($company, $account)->status)->toBe(SetupFeeState::PAID);

    $licences = undoLicenceSnapshot($this, $company);
    $deadline = $account->mandate_deadline_at?->toIso8601String();
    $this->gc->calls = [];
    Mail::fake();

    // The Billing tab offers the undo with what it will touch.
    $this->actingAs($admin, 'admin')->get(route('admin.tenants.show', $company))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.directDebit.upfront.undo.payment', $payment->number)
            ->where('billing.directDebit.upfront.undo.amount', '£1,140.00')
            ->where('billing.directDebit.upfront.undo.invoices', [$invoice->number]));

    $this->actingAs($admin, 'admin')
        ->post(route('admin.billing.tenants.upfront.undo', $company), ['reason' => 'Customer paid nothing; pays in 3 monthly instalments'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $payment->refresh();
    $invoice->refresh();
    $account = $this->billingAccountOf($company);
    $state = SetupFeeState::for($company, $account);

    expect($payment->reversed_at)->not->toBeNull()
        ->and($payment->reversal_reason)->toBe('Recorded by mistake: Customer paid nothing; pays in 3 monthly instalments')
        ->and($invoice->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->void_reason)->toBe('Recorded by mistake: Customer paid nothing; pays in 3 monthly instalments')
        ->and($invoice->voided_by_admin_id)->toBe((string) $admin->id)
        ->and($account->upfront_amount)->toBeNull()
        ->and($account->upfront_method)->toBeNull()
        ->and($account->upfront_recorded_at)->toBeNull()
        ->and($account->setup_fee_invoiced_at)->toBeNull()
        ->and($account->setup_fee_override)->toBe('950.00')
        ->and($account->mandate_deadline_at?->toIso8601String())->toBe($deadline)
        ->and($state->status)->toBe(SetupFeeState::UNPAID)
        ->and($state->invoiced)->toBeFalse()
        ->and($state->total)->toBe('1140.00')
        ->and(undoLicenceSnapshot($this, $company))->toBe($licences)
        ->and($this->gc->calls)->toBe([]);
    Mail::assertNothingOutgoing();

    $audit = AuditLog::query()->where('action', 'billing.upfront_undone')->sole();
    expect($audit->company_id)->toBe($company->id)
        ->and($audit->actor_id)->toBe((string) $admin->id)
        ->and($audit->meta['amount'])->toBe('1140.00')
        ->and($audit->meta['payment'])->toBe($payment->number)
        ->and($audit->meta['invoices'])->toBe([$invoice->number])
        ->and($audit->meta['reason'])->toBe('Customer paid nothing; pays in 3 monthly instalments');

    // The other business is untouched.
    expect(Payment::withoutCompanyScope()->where('company_id', $other->id)->sole()->reversed_at)->toBeNull()
        ->and(undoSetupInvoices($other)[0]->status)->toBe(InvoiceStatus::Paid)
        ->and($this->billingAccountOf($other)->upfront_recorded_at)->not->toBeNull();

    // The undo is no longer offered.
    $this->actingAs($admin, 'admin')->get(route('admin.tenants.show', $company))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('billing.directDebit.upfront.undo', null)
            ->where('billing.directDebit.setupFee.invoicedAt', null));

    // (a) Payment settings: 3 instalments. (b) Invoice setup fee: 3 invoices of £380.00, a month apart.
    $this->actingAs($admin, 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => '950.00', 'setup_fee_instalments' => 3,
    ])->assertSessionHasNoErrors();
    $this->actingAs($admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();

    $parts = array_values(array_filter(undoSetupInvoices($company), fn (Invoice $part) => $part->status !== InvoiceStatus::Void));
    expect($parts)->toHaveCount(3)
        ->and(array_map(fn (Invoice $part) => $part->total, $parts))->toBe(['380.00', '380.00', '380.00'])
        ->and(array_map(fn (Invoice $part) => $part->status, $parts))->toBe([InvoiceStatus::Issued, InvoiceStatus::Issued, InvoiceStatus::Issued])
        ->and($parts[1]->due_date->format('Y-m-d'))->toBe('2026-12-05')
        ->and($parts[2]->due_date->format('Y-m-d'))->toBe('2027-01-05')
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->total)->toBe('1140.00');

    // (c) Record the first instalment (Record setup fee payment).
    $this->actingAs($admin, 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($this->fresh($parts[0])->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($parts[1])->status)->toBe(InvoiceStatus::Issued)
        ->and($state->status)->toBe(SetupFeeState::PART_PAID)
        ->and($state->paid)->toBe('380.00')
        ->and($state->owed())->toBe('760.00')
        ->and($this->billingAccountOf($company)->upfront_amount)->toBe('380.00')
        ->and(Payment::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(2); // the reversed one stays
});

test('instalments invoiced at onboarding: the paid first part and the unpaid parts are all voided', function () {
    $company = undoOnboarded($this, instalments: 3);
    [$first, $second, $third] = undoSetupInvoices($company);
    expect($first->status)->toBe(InvoiceStatus::Paid)->and($second->status)->toBe(InvoiceStatus::Issued);

    app(UndoUpfrontPayment::class)->handle($company, 'Nothing received');

    foreach ([$first, $second, $third] as $invoice) {
        expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Void);
    }

    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID)
        ->and(AuditLog::query()->where('action', 'billing.upfront_undone')->sole()->meta['invoices'])->toBe([$first->number, $second->number, $third->number]);
});

test('a £0 "nothing to pay" record is cleared so the setup fee can be set', function () {
    $company = $this->trialTenant('Patel News', 1, '2026-11-12 10:00', 'PTL');
    app(OnboardTenantBilling::class)->handle($company, new UpfrontPayment('0', PaymentMethod::Cash));
    expect($this->billingAccountOf($company)->upfront_amount)->toBe('0.00');

    app(UndoUpfrontPayment::class)->handle($company, 'The fee was agreed, not waived');

    $account = $this->billingAccountOf($company);
    expect($account->upfront_recorded_at)->toBeNull()->and($account->setup_fee_invoiced_at)->toBeNull()
        ->and(Payment::withoutCompanyScope()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'billing.upfront_undone')->sole()->meta['payment'])->toBeNull();
});

test('refused when no upfront payment is recorded, and when undone twice', function () {
    $company = $this->trialTenant('Patel News', 1, '2026-11-12 10:00', 'PTL');
    undoRefusal($company, 'No upfront payment is recorded');

    $paid = undoOnboarded($this, 'Khan Mini Mart', 'KHN');
    app(UndoUpfrontPayment::class)->handle($paid, 'Nothing received');
    undoRefusal($paid, 'No upfront payment is recorded');

    expect(AuditLog::query()->where('action', 'billing.upfront_undone')->count())->toBe(1);
});

test('refused for a Direct Debit payment', function () {
    $company = undoOnboarded($this);
    Payment::withoutCompanyScope()->where('company_id', $company->id)->sole()->forceFill(['method' => PaymentMethod::DirectDebit, 'gateway' => 'gocardless'])->save();

    undoRefusal($company, 'was paid by direct debit');
});

test('refused when the payment is already reversed', function () {
    $company = undoOnboarded($this);
    app(ReversePayment::class)->handle(Payment::withoutCompanyScope()->where('company_id', $company->id)->sole(), 'Bounced');

    undoRefusal($company, 'is already reversed');
});

test('refused when a setup fee invoice has another payment or a credit note', function () {
    $paidTwice = undoOnboarded($this, instalments: 3);
    app(RecordUpfrontPayment::class)->handle($paidTwice, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    undoRefusal($paidTwice, 'has another payment on it');

    $credited = undoOnboarded($this, 'Khan Mini Mart', 'KHN', instalments: 3);
    $this->creditIt(undoSetupInvoices($credited)[1], '10.00');
    undoRefusal($credited, 'has a credit note');

    expect(Payment::withoutCompanyScope()->whereNotNull('reversed_at')->count())->toBe(0)
        ->and(Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Void->value)->count())->toBe(0);
});

test('a reason is required', function () {
    $company = undoOnboarded($this);

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->post(route('admin.billing.tenants.upfront.undo', $company), ['reason' => '  '])
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->post(route('admin.billing.tenants.upfront.undo', $company), ['reason' => str_repeat('x', 501)])
        ->assertSessionHasErrors('reason');

    expect(Payment::withoutCompanyScope()->sole()->reversed_at)->toBeNull();
});

test('guests are redirected, customers and admins without billing.manage cannot undo', function () {
    $company = undoOnboarded($this);
    $url = route('admin.billing.tenants.upfront.undo', $company);

    $this->post($url, ['reason' => 'x'])->assertRedirect(route('admin.login'));
    $this->actingAs($this->addMember($company, CompanyRole::Owner))->post($url, ['reason' => 'x'])->assertRedirect(route('admin.login'));

    foreach ([AdminRole::Sales, AdminRole::Support] as $role) {
        $this->actingAs($this->admin($role), 'admin')->post($url, ['reason' => 'x'])->assertForbidden();
    }

    expect(Payment::withoutCompanyScope()->sole()->reversed_at)->toBeNull()
        ->and($this->billingAccountOf($company)->upfront_recorded_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'billing.upfront_undone')->count())->toBe(0);
});
