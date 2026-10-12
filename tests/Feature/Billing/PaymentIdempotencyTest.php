<?php

use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-9 (docs/audit/2026-10-11-billing.md): Record payment and Record setup fee payment send a key made when
 * the form opens; the same key records one payment per business, however many times the form is submitted (double
 * click, browser retry). A new form (a new key) records a new payment as before.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    $this->atLondon('2026-10-20 10:00');
});

/** A setup-only business whose £1,200 setup fee is paid in 3 instalments. */
function idempotentInstalments(object $test, string $name = 'Patel News', string $code = 'PTL'): Company
{
    $company = $test->trialTenant($name, 1, '2026-10-26 10:00', $code);
    $test->standardPlan()->forceFill(['setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness])->save();
    $account = $test->billingAccountOf($company);
    $account->setup_fee_instalments = 3;
    $account->save();

    return $company;
}

test('the same setup fee payment submitted twice pays one instalment', function () {
    $company = idempotentInstalments($this);
    $input = new UpfrontPayment(null, PaymentMethod::Cash, 'Receipt 0042', idempotencyKey: (string) Str::uuid());

    app(RecordUpfrontPayment::class)->handle($company, $input);
    app(RecordUpfrontPayment::class)->handle($company, $input); // the double click / resubmitted form

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->where('reference', 'Receipt 0042')->count())->toBe(1)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->paidParts)->toBe(1);
});

test('a second setup fee form (a new key) pays the next instalment, as before', function () {
    $company = idempotentInstalments($this);

    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash, 'Receipt 1', idempotencyKey: (string) Str::uuid()));
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash, 'Receipt 2', idempotencyKey: (string) Str::uuid()));
    // Callers without a key (wizard, trial approval, older pages) behave as before.
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Cash, 'Receipt 3'));

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(3)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->isSettled())->toBeTrue();
});

test('Record payment with the same key twice records one payment', function () {
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company);
    $key = (string) Str::uuid();
    $input = new NewPayment(method: PaymentMethod::Cash, amount: '20.00', receivedAt: CarbonImmutable::now(), reference: 'Till 1', idempotencyKey: $key);

    $first = app(RecordPayment::class)->handle($company, $input);
    $second = app(RecordPayment::class)->handle($company, $input);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->payment->id)->toBe($first->payment->id)
        ->and(Payment::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1)
        ->and($this->fresh($invoice)->amount_paid)->toBe('20.00');
});

test('the same key on another business is a different payment (keys are per business)', function () {
    $a = $this->payingTenant('Shop A', 1, 'SHA');
    $b = $this->payingTenant('Shop B', 1, 'SHB');
    $key = (string) Str::uuid();

    foreach ([$a, $b] as $company) {
        app(RecordPayment::class)->handle($company, new NewPayment(method: PaymentMethod::Cash, amount: '5.00', receivedAt: CarbonImmutable::now(), idempotencyKey: $key));
    }

    expect(Payment::withoutCompanyScope()->where('company_id', $a->id)->count())->toBe(1)
        ->and(Payment::withoutCompanyScope()->where('company_id', $b->id)->count())->toBe(1);
});

test('the admin Record payment form resubmitted with its key records one payment and says so', function () {
    $company = $this->payingTenant(tills: 1);
    $this->issuedFor($company);
    $form = ['method' => 'cash', 'amount' => '30.00', 'received_on' => '2026-10-20', 'reference' => 'Cash', 'allocation' => 'auto', 'idempotency_key' => (string) Str::uuid()];
    $admin = $this->actingAs($this->admin(), 'admin');

    $admin->post(route('admin.billing.tenants.payments.store', $company), $form)->assertSessionHasNoErrors();
    $admin->post(route('admin.billing.tenants.payments.store', $company), $form)->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'already recorded'));

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1);

    // A key that is not a UUID is refused.
    $admin->post(route('admin.billing.tenants.payments.store', $company), ['idempotency_key' => 'x'] + $form)->assertSessionHasErrors('idempotency_key');
});

test('the admin Record setup fee payment form resubmitted with its key pays one instalment', function () {
    $company = idempotentInstalments($this);
    $form = ['upfront_method' => 'cash', 'upfront_reference' => 'Receipt 7', 'idempotency_key' => (string) Str::uuid()];
    $admin = $this->actingAs($this->admin(), 'admin');

    $admin->post(route('admin.billing.tenants.upfront', $company), $form)->assertSessionHasNoErrors();
    $admin->post(route('admin.billing.tenants.upfront', $company), $form)->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'already recorded'));

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->count())->toBe(3)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->paidParts)->toBe(1);
});

test('PK: a JazzCash payment form submitted twice records one payment', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $company = $this->payingTenant('Lahore Mart', 1, 'LHR');
    $this->issuedFor($company);
    $form = ['method' => 'jazzCash', 'amount' => '25', 'received_on' => '2026-10-20', 'reference' => 'TID 99887766', 'allocation' => 'auto', 'idempotency_key' => (string) Str::uuid()];
    $admin = $this->actingAs($this->admin(), 'admin');

    $admin->post(route('admin.billing.tenants.payments.store', $company), $form)->assertSessionHasNoErrors();
    $admin->post(route('admin.billing.tenants.payments.store', $company), $form)->assertSessionHasNoErrors();

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->where('reference', 'TID 99887766')->count())->toBe(1);
})->group('country-pk');
