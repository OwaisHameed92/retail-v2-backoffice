<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\UndoUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\SetupFeeDeposit;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Owner 2026-11-11, setup fee deposit (first instalment): the real case is Asia Halal Food Stores, setup fee £1,098 +
 * VAT paid as £350 + VAT now, then 11 × £68 + VAT. Part 1 is the deposit; the rest of the VAT-inclusive total is split
 * equally across the other parts by the rule the equal split always used. No deposit = exactly as before.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-11-11 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** A paying business with its setup fee agreed (net), in `$instalments` parts, with an optional deposit. */
function depositBusiness(object $test, string $fee, int $instalments, ?string $deposit, string $name = 'Asia Halal Food Stores', string $code = 'AHF'): Company
{
    $company = $test->directDebitTenant($name, tills: 1, code: $code, setupFee: $fee, instalments: $instalments);
    $test->billingAccountOf($company)->forceFill(['setup_fee_deposit' => $deposit, 'setup_fee_method' => 'manual'])->save();

    return $company->refresh();
}

/** @return list<array{net: string, vat: string, gross: string}> */
function depositSchedule(object $test, Company $company): array
{
    return SetupFee::schedule($company, $test->billingAccountOf($company));
}

/**
 * The equal split exactly as SetupFee::schedule worked before the deposit (copied from the code before the change), to
 * prove a business without a deposit gets the very same parts.
 *
 * @return list<array{net: string, vat: string, gross: string}>
 */
function equalSplitBeforeDeposit(string $net, int $parts, string $vatRate): array
{
    $gross = Money::add($net, InvoiceMaths::vatOn($net, $vatRate));
    $each = Money::round(bcdiv(Money::parse($gross), (string) $parts, 6));
    $schedule = [];
    $left = $gross;

    for ($i = 1; $i <= $parts; $i++) {
        $partGross = $i === $parts ? $left : $each;
        $left = Money::sub($left, $partGross);
        $split = InvoiceMaths::splitGross($partGross, $vatRate);
        $schedule[] = ['net' => $split['net'], 'vat' => $split['vat'], 'gross' => $partGross];
    }

    return $schedule;
}

/** @return list<Invoice> */
function depositInvoices(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)
        ->orderBy('due_date')->orderBy('sequence')->get()->all();
}

function depositSettings(object $test, Company $company, array $fields, ?object $admin = null): TestResponse
{
    return $test->actingAs($admin ?? $test->admin(), 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => '1098.00', 'setup_fee_instalments' => 12, ...$fields,
    ]);
}

test('Asia Halal: £1,098 + VAT in 12 with a £350 deposit is £350 + VAT, then 11 × £68 + VAT', function () {
    $company = depositBusiness($this, '1098.00', 12, '350.00');
    $schedule = depositSchedule($this, $company);

    expect($schedule)->toHaveCount(12)
        ->and($schedule[0])->toBe(['net' => '350.00', 'vat' => '70.00', 'gross' => '420.00'])
        ->and(array_slice($schedule, 1))->each->toBe(['net' => '68.00', 'vat' => '13.60', 'gross' => '81.60'])
        ->and(SetupFee::totals($company, $this->billingAccountOf($company)))->toBe(['net' => '1098.00', 'vat' => '219.60', 'gross' => '1317.60'])
        ->and(SetupFeeDeposit::text($company, $this->billingAccountOf($company)))->toBe('£420.00 deposit, then 11 × £81.60');
});

test('the pennies go to the last part: £1,000 + VAT in 12 with a £333.33 deposit', function () {
    $company = depositBusiness($this, '1000.00', 12, '333.33');
    $schedule = depositSchedule($this, $company);

    expect($schedule[0])->toBe(['net' => '333.33', 'vat' => '66.67', 'gross' => '400.00'])
        ->and(array_slice($schedule, 1, 10))->each->toBe(['net' => '60.61', 'vat' => '12.12', 'gross' => '72.73'])
        ->and($schedule[11])->toBe(['net' => '60.58', 'vat' => '12.12', 'gross' => '72.70'])
        ->and(Money::sum(array_column($schedule, 'gross')))->toBe('1200.00')
        ->and(SetupFeeDeposit::text($company, $this->billingAccountOf($company)))->toBe('£400.00 deposit, then 11 × £72.73 (the last £72.70)');

    // Two parts: the deposit, then the rest in one.
    $two = depositBusiness($this, '1000.00', 2, '250.00', 'Two Part Stores', 'TWO');
    expect(array_column(depositSchedule($this, $two), 'gross'))->toBe(['300.00', '900.00'])
        ->and(SetupFeeDeposit::text($two, $this->billingAccountOf($two)))->toBe('£300.00 deposit, then £900.00');

    // Without VAT the parts are the net amounts.
    $this->setVat(true, false, $two);
    expect(depositSchedule($this, $two))->toBe([['net' => '250.00', 'vat' => '0.00', 'gross' => '250.00'], ['net' => '750.00', 'vat' => '0.00', 'gross' => '750.00']]);
});

test('no deposit: the parts and the invoices are exactly what they were before', function (string $fee, int $instalments, string $code) {
    $company = depositBusiness($this, $fee, $instalments, null, 'Regression '.$fee.'/'.$instalments, $code);

    expect(depositSchedule($this, $company))->toBe(equalSplitBeforeDeposit($fee, $instalments, '20.00'))
        ->and(SetupFeeDeposit::text($company, $this->billingAccountOf($company)))->toBeNull();

    $invoices = app(ChargeSetupFee::class)->handle($company);
    $labels = InvoiceLine::withoutCompanyScope()->where('company_id', $company->id)->orderBy('created_at')->pluck('description')->all();

    expect(array_map(fn (Invoice $invoice) => [$invoice->subtotal, $invoice->vat_total, $invoice->total], $invoices))
        ->toBe(array_map(fn (array $part) => [$part['net'], $part['vat'], $part['gross']], equalSplitBeforeDeposit($fee, $instalments, '20.00')))
        ->and($labels[0])->toBe(($instalments === 1 ? 'Setup fee' : "Setup fee, instalment 1 of {$instalments}").' · '.$company->name)
        ->and($this->billingAccountOf($company)->setup_fee_deposit)->toBeNull();
})->with([
    ['950.00', 3, 'RGA'],
    ['1000.00', 12, 'RGB'],
    ['1098.00', 12, 'RGC'],
    ['99.99', 7, 'RGD'],
    ['500.00', 1, 'RGE'],
]);

test('a deposit that no longer fits the fee is not used: equal parts, and the deposit is cleared when invoiced', function () {
    // The plan's fee (no override) dropped below the deposit after it was set.
    $company = depositBusiness($this, '1098.00', 12, '350.00');
    $this->billingAccountOf($company)->forceFill(['setup_fee_override' => '300.00'])->save();

    expect(depositSchedule($this, $company))->toBe(equalSplitBeforeDeposit('300.00', 12, '20.00'));
    app(ChargeSetupFee::class)->handle($company);
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBeNull();

    // One instalment: never a deposit.
    $one = depositBusiness($this, '1098.00', 1, '350.00', 'One Part Stores', 'ONE');
    expect(depositSchedule($this, $one))->toBe(equalSplitBeforeDeposit('1098.00', 1, '20.00'));
});

test('Payment settings: the deposit is saved, audited, and checked against the fee and the instalments', function () {
    $company = depositBusiness($this, '1098.00', 12, null);

    depositSettings($this, $company, ['setup_fee_deposit' => '£350'])->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBe('350.00');
    $audit = AuditLog::query()->where('action', 'billing.dd_settings_updated')->where('company_id', $company->id)->latest('id')->firstOrFail();
    expect($audit->after['setup_fee_deposit'] ?? null)->toBe('350.00')->and($audit->before)->toHaveKey('setup_fee_deposit')->and($audit->before['setup_fee_deposit'])->toBeNull();

    depositSettings($this, $company, ['setup_fee_deposit' => '0'])->assertSessionHasErrors(['setup_fee_deposit' => 'The deposit must be more than £0.00.']);
    depositSettings($this, $company, ['setup_fee_deposit' => '1098.00'])->assertSessionHasErrors(['setup_fee_deposit' => 'The deposit must be less than the setup fee (£1,098.00 before VAT).']);
    depositSettings($this, $company, ['setup_fee_deposit' => '350.00', 'setup_fee_instalments' => 1])
        ->assertSessionHasErrors(['setup_fee_deposit' => 'A deposit needs 2 or more instalments: the deposit first, then the rest.']);
    depositSettings($this, $company, ['setup_fee_deposit' => '1097.99'])->assertSessionHasErrors(['setup_fee_deposit' => 'The deposit leaves too little to split into 11 more instalments.']);
    depositSettings($this, $company, ['setup_fee_deposit' => '35.123'])->assertSessionHasErrors(['setup_fee_deposit' => 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.']);
    // The plan's fee (no override) counts when there is no agreed fee.
    depositSettings($this, $company, ['setup_fee_override' => '', 'setup_fee_deposit' => '10.00'])->assertSessionHasErrors(['setup_fee_deposit' => 'There is no setup fee to take a deposit from.']);
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBe('350.00');

    // Blank clears it; an older form without the field keeps it.
    depositSettings($this, $company, [])->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBe('350.00');
    depositSettings($this, $company, ['setup_fee_deposit' => ''])->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBeNull();

    // Once invoiced it cannot change.
    depositSettings($this, $company, ['setup_fee_deposit' => '350.00'])->assertSessionHasNoErrors();
    app(ChargeSetupFee::class)->handle($company);
    depositSettings($this, $company, ['setup_fee_deposit' => '300.00'])
        ->assertSessionHasErrors(['setup_fee_override' => 'The setup fee is already invoiced, so it cannot change. Void or credit those invoices instead.']);
});

test('only billing.manage admins can set the deposit; another business is never touched', function () {
    $company = depositBusiness($this, '1098.00', 12, null);
    $other = depositBusiness($this, '1098.00', 12, null, 'Other Store', 'OTH');

    $form = ['billing_mode' => 'directDebit', 'setup_fee_instalments' => 12, 'setup_fee_deposit' => '350.00'];
    // A guest is sent to the admin sign-in; the business's own owner is not an admin either.
    $this->put(route('admin.billing.tenants.direct-debit.settings', $company), $form)->assertRedirect(route('admin.login'));
    $this->actingAs($this->ownerOf($company), 'web')->put(route('admin.billing.tenants.direct-debit.settings', $company), $form)
        ->assertRedirect(route('admin.login'));

    foreach ([AdminRole::Sales, AdminRole::Support] as $role) {
        depositSettings($this, $company, ['setup_fee_deposit' => '350.00'], $this->admin($role))->assertForbidden();
    }
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBeNull();

    depositSettings($this, $company, ['setup_fee_deposit' => '350.00'], $this->admin(AdminRole::Accounts))->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBe('350.00')
        ->and($this->billingAccountOf($other)->setup_fee_deposit)->toBeNull();

    // Each business's own page shows only its own split.
    $this->actingAs($this->ownerOf($other), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('upfront.split', null)->where('setupFee.split', null)->where('setupFee.parts.0.label', 'Part 1 of 12'));
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('upfront.split', '£420.00 deposit, then 11 × £81.60')
        ->where('setupFee.parts.0.label', 'Deposit (part 1 of 12)')->where('setupFee.parts.0.amount', '£420.00')
        ->where('setupFee.parts.11.amount', '£81.60'));
});

test('invoicing: the deposit and each instalment, with their amounts and due dates a month apart', function () {
    $company = depositBusiness($this, '1098.00', 12, '350.00');
    $invoices = app(ChargeSetupFee::class)->handle($company);
    $lines = InvoiceLine::withoutCompanyScope()->where('company_id', $company->id)->get()->keyBy('invoice_id');

    expect($invoices)->toHaveCount(12)
        ->and([$invoices[0]->subtotal, $invoices[0]->vat_total, $invoices[0]->total, $invoices[0]->balance])->toBe(['350.00', '70.00', '420.00', '420.00'])
        ->and($lines[$invoices[0]->id]->description)->toBe('Setup fee, deposit (instalment 1 of 12) · Asia Halal Food Stores')
        ->and($lines[$invoices[1]->id]->description)->toBe('Setup fee, instalment 2 of 12 · Asia Halal Food Stores')
        ->and($invoices[0]->due_date->format('Y-m-d'))->toBe('2026-11-18');

    foreach (array_slice($invoices, 1) as $i => $invoice) {
        expect([$invoice->subtotal, $invoice->vat_total, $invoice->total])->toBe(['68.00', '13.60', '81.60'])
            ->and($invoice->due_date->format('Y-m-d'))->toBe(CarbonImmutable::parse('2026-11-11')->addMonthsNoOverflow($i + 1)->format('Y-m-d'));
    }

    expect(AuditLog::query()->where('action', 'billing.setup_fee_invoiced')->where('company_id', $company->id)->sole()->after)
        ->toMatchArray(['net' => '1098.00', 'instalments' => 12, 'deposit' => '350.00']);
});

test('the deposit is recorded by hand first; the status card and the tills follow it', function () {
    $company = depositBusiness($this, '1098.00', 12, '350.00');
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', '£1,317.60 — not paid yet: £420.00 deposit, then 11 × £81.60 (cash, card or bank transfer)'));

    // An amount typed in the dialog must still leave room for the deposit.
    expect(fn () => app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment('300.00', PaymentMethod::Cash)))
        ->toThrow(ValidationException::class, 'The deposit must be less than the setup fee (£300.00 before VAT). Change the deposit in Payment settings first.');

    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'card', 'upfront_reference' => 'Deposit', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    $invoices = depositInvoices($company);
    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($invoices)->toHaveCount(12)
        ->and($invoices[0]->status)->toBe(InvoiceStatus::Paid)->and($invoices[0]->total)->toBe('420.00')
        ->and($invoices[1]->status)->toBe(InvoiceStatus::Issued)
        ->and($state->status)->toBe(SetupFeeState::PART_PAID)->and($state->paid)->toBe('420.00')->and($state->owed())->toBe('897.60')
        ->and($state->nextDue?->format('Y-m-d'))->toBe('2026-12-11')
        ->and($this->billingAccountOf($company)->upfront_amount)->toBe('420.00');

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', '£420.00 paid (1 of 12 instalments) by card on 11 Nov 2026 — £897.60 left, next due 11 Dec 2026')
        ->where('upfront.split', '£420.00 deposit, then 11 × £81.60'));

    // The next form pays instalment 2 (£81.60).
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    expect($this->fresh($invoices[1])->status)->toBe(InvoiceStatus::Paid)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->paid)->toBe('501.60');
});

test('setup only with a deposit: the paid deposit keeps the tills paid to the next instalment\'s due date', function () {
    $company = $this->trialTenant('Asia Halal Food Stores', 1, '2026-11-18 10:00', 'AHF');
    $this->standardPlan()->forceFill(['setup_fee' => '1098.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => null])->save();
    $this->billingAccountOf($company)->forceFill(['setup_fee_instalments' => 12, 'setup_fee_deposit' => '350.00'])->save();
    $licence = $this->licencesOf($company)[0];

    app(RecordUpfrontPayment::class)->handle($company->refresh(), new UpfrontPayment(null, PaymentMethod::Cash));

    expect(depositInvoices($company)[0]->total)->toBe('420.00')
        ->and($this->licenceFresh($licence)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-12-11'));
});

test('Undo upfront payment keeps the deposit: invoiced again, the same deposit and instalments', function () {
    $company = depositBusiness($this, '1098.00', 12, '350.00');
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash));

    app(UndoUpfrontPayment::class)->handle($company, 'Recorded by mistake: the deposit comes next week');
    expect($this->billingAccountOf($company)->setup_fee_deposit)->toBe('350.00')
        ->and($this->billingAccountOf($company)->setup_fee_invoiced_at)->toBeNull();

    $again = app(ChargeSetupFee::class)->handle($company);
    expect(array_map(fn (Invoice $invoice) => $invoice->total, $again))->toBe(['420.00', ...array_fill(0, 11, '81.60')]);
});
