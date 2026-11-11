<?php

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Actions\CollectSetupFeeByDirectDebit;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Owner 2026-11-11, the real deal: Asia Halal Food Stores (UK). Setup fee £1,098 + VAT: a deposit of £350 + VAT paid
 * by hand, then 11 monthly instalments of £68 + VAT by Direct Debit ("Setup fee by Direct Debit", the per-business
 * exception), plus the monthly subscription of £12 + VAT by Direct Debit, which starts once the deposit is paid.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-11-11 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->admin = $this->admin();
});

/** A one-till business on Direct Debit at £12 + VAT a month, nothing agreed for the setup fee yet. */
function asiaHalal(object $test): Company
{
    $company = $test->directDebitTenant('Asia Halal Food Stores', tills: 1, code: 'AHF', setupFee: null, instalments: 1);
    $test->billingAccountOf($company)->forceFill(['price_monthly_override' => '12.00', 'setup_fee_method' => 'manual'])->save();

    return $company->refresh();
}

/** Payment settings, as staff fill it in for the deal. */
function asiaHalalSettings(object $test, Company $company): void
{
    $test->actingAs($test->admin, 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => '1098.00', 'setup_fee_instalments' => 12,
        'setup_fee_deposit' => '350.00', 'setup_fee_by_direct_debit' => true,
    ])->assertSessionHasNoErrors();
}

/** @return list<Invoice> */
function asiaHalalInvoices(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)
        ->orderBy('due_date')->orderBy('sequence')->get()->all();
}

test('Asia Halal: deposit by hand, the other 11 instalments by Direct Debit, the monthly starts after the deposit', function () {
    $company = asiaHalal($this);
    asiaHalalSettings($this, $company);
    $account = $this->billingAccountOf($company);

    expect($account->setup_fee_deposit)->toBe('350.00')->and($account->setup_fee_by_direct_debit)->toBeTrue();

    // Before anything is paid: the status card and the Direct Debit setup email say how it is split.
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', '£1,317.60 — not paid yet: £420.00 deposit, then 11 × £81.60 by Direct Debit')
        ->where('setupFee.split', '£420.00 deposit, then 11 × £81.60'));
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.deposit', '350.00')
        ->where('billing.directDebit.setupFee.split', '£420.00 deposit, then 11 × £81.60'));

    $this->sendSetupEmail($company);
    Mail::assertQueued(DirectDebitSetupMail::class, fn (DirectDebitSetupMail $mail) => $mail->data->setupFeeSplit === '£420.00 deposit, then 11 × £81.60'
        && str_contains(html_entity_decode(strip_tags($mail->render())), '£1,317.60 (VAT included), collected by this Direct Debit: £420.00 deposit, then 11 × £81.60, a month apart'));

    // The owner sets up the Direct Debit: nothing is invoiced yet, so nothing is collected and no subscription yet.
    $this->setUpMandate($company);
    expect($this->gc->oneOffPayments())->toBe([])->and($this->gc->subscriptions)->toBe([]);

    // Staff record the £350 + VAT deposit by hand (card): the 12 parts are invoiced, the deposit paid, none charged.
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'card', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $parts = asiaHalalInvoices($company);
    expect($parts)->toHaveCount(12)
        ->and($parts[0]->status)->toBe(InvoiceStatus::Paid)->and($parts[0]->total)->toBe('420.00')
        ->and(array_map(fn (Invoice $part) => $part->total, array_slice($parts, 1)))->each->toBe('81.60')
        ->and($parts[1]->due_date->format('Y-m-d'))->toBe('2026-12-11')
        ->and($parts[11]->due_date->format('Y-m-d'))->toBe('2027-10-11')
        ->and($this->gc->oneOffPayments())->toBe([])
        ->and(GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $parts[0]->id)->exists())->toBeFalse();

    // Recurring start: the deposit is the first instalment, so the £12 + VAT monthly Direct Debit starts now.
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PART_PAID)
        ->and($this->gc->lastSubscription())->not->toBeNull()
        ->and($this->gc->lastSubscription()->amountPence)->toBe(1440);

    // The daily run never charges the hand-paid deposit; instalment 2 goes on the mandate 10 days before it is due.
    $this->runBillingOn('2026-11-12');
    $this->runBillingOn('2026-11-30');
    expect($this->gc->oneOffPayments())->toBe([]);
    $this->runBillingOn('2026-12-01');

    $made = $this->gc->oneOffPayments();
    expect($made)->toHaveCount(1)
        ->and($made[0]->amountPence)->toBe(8160)
        ->and($made[0]->chargeDate)->toBe('2026-12-11')
        ->and($made[0]->metadata['invoice_id'])->toBe($parts[1]->id);
    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $made[0]->id)->sole();
    expect($row->instalment)->toBe(2)->and($row->instalments)->toBe(12);

    // Collected: instalment 2 paid by Direct Debit; again, nothing is ever made for the deposit.
    $this->atLondon('2026-12-15 09:00');
    $this->paymentEvent($made[0], PaymentStatus::Confirmed, 'confirmed')->assertOk();
    expect($this->fresh($parts[1])->status)->toBe(InvoiceStatus::Paid)
        ->and(app(CollectSetupFeeByDirectDebit::class)->handle($company))->toBe([])
        ->and(collect($this->gc->oneOffPayments())->contains(fn (GcPayment $payment) => $payment->metadata['invoice_id'] === $parts[0]->id))->toBeFalse();

    // The admin Billing tab and the tenant page label part 1 as the deposit.
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.parts.0.label', 'Deposit (part 1 of 12)')
        ->where('billing.directDebit.setupFee.parts.0.state', 'paid')
        ->where('billing.directDebit.setupFee.parts.1.state', 'paid')
        ->where('billing.directDebit.setupFee.parts.2.state', 'planned'));
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('setupFee.parts.0.label', 'Deposit (part 1 of 12)')
        ->where('setupFee.parts.2.directDebit.label', 'Collected by Direct Debit on')
        ->where('setupFee.parts.2.directDebit.date', '2027-01-11'));
});

test('with the deposit invoiced before the mandate, it can still be recorded by hand and is never charged', function () {
    $company = asiaHalal($this);
    asiaHalalSettings($this, $company);

    // Invoice setup fee first (no mandate yet): nothing goes to GoCardless.
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();
    $parts = asiaHalalInvoices($company);
    expect($parts[0]->total)->toBe('420.00')->and($this->gc->oneOffPayments())->toBe([]);

    // The deposit comes in by bank transfer before the mandate exists.
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'bankTransfer', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    expect($this->fresh($parts[0])->status)->toBe(InvoiceStatus::Paid);

    // The mandate arrives: only open instalments coming due are collected; the paid deposit never is.
    $this->setUpMandate($company);
    $this->runBillingOn('2026-12-01');
    expect(array_map(fn (GcPayment $payment) => $payment->metadata['invoice_id'], $this->gc->oneOffPayments()))->toBe([$parts[1]->id])
        ->and($this->gc->lastSubscription())->not->toBeNull();
});

test('deposit by Direct Debit too: the status card says when it is collected, never "tills locked" or "cash, card or bank transfer"', function () {
    $company = asiaHalal($this);
    asiaHalalSettings($this, $company);
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();

    // Invoiced, no mandate yet: the card waits for the Direct Debit (its own state), nothing about a setup fee lock.
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.status.state', 'waitingForDirectDebit'));

    // The mandate arrives: the deposit goes on it and the card names the collection day.
    $this->setUpMandate($company);
    $charge = GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->sole()->charge_date;
    $day = ($charge ?? asiaHalalInvoices($company)[0]->due_date)->format('j M Y');

    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.status.state', 'setupFeeDue')
        ->where('billing.status.tone', 'warning')
        ->where('billing.status.headline', "Setup fee by Direct Debit — first payment £420.00 on {$day}")
        ->where('billing.status.next.text', "The first setup fee payment (£420.00) is collected by Direct Debit on {$day}. The monthly fee starts once it is paid. The tills keep working."));
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page
        ->where('status.next.text', "Your first setup fee payment (£420.00) is collected by Direct Debit on {$day}. The monthly fee starts once it is paid. Your tills keep working."));
});
