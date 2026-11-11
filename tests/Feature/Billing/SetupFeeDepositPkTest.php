<?php

use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/*
 * Owner 2026-11-11 on the Pakistan instance (manual collection, no Direct Debit, no VAT): the setup fee deposit works
 * for instalments paid by hand. Rs 150,000 in 5 parts with a Rs 50,000 deposit → Rs 50,000, then 4 × Rs 25,000.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $this->gc = FakeGoCardlessClient::install();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00', 'Asia/Karachi'));
});

test('Pakistan: the deposit is set in Payment settings and paid by hand, the rest in equal parts', function () {
    $company = $this->trialTenant('Lahore Mart', 1, '2026-11-18 10:00', 'LHR');
    $this->standardPlan()->forceFill(['setup_fee' => '150000.00', 'price_monthly' => '2500.00', 'price_yearly' => '0.00', 'billing_type' => null])->save();

    $this->actingAs($this->admin(), 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'upfrontCash', 'setup_fee_override' => '', 'setup_fee_instalments' => 5, 'setup_fee_deposit' => '50000',
    ])->assertSessionHasNoErrors();
    expect(ManualCollection::active())->toBeTrue()
        ->and($this->billingAccountOf($company)->setup_fee_deposit)->toBe('50000.00');

    $split = BillingFormat::money('50000.00').' deposit, then 4 × '.BillingFormat::money('25000.00');
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', BillingFormat::money('150000.00')." — not paid yet: {$split} (".ManualCollection::methodsText().')'));

    app(RecordUpfrontPayment::class)->handle($company->refresh(), new UpfrontPayment(null, PaymentMethod::JazzCash));

    $parts = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)
        ->orderBy('due_date')->orderBy('sequence')->get();
    expect($parts->pluck('total')->all())->toBe(['50000.00', '25000.00', '25000.00', '25000.00', '25000.00'])
        ->and($parts[0]->status)->toBe(InvoiceStatus::Paid)->and($parts[0]->currency)->toBe('PKR')
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PART_PAID)
        ->and($this->gc->calls)->toBe([]);
})->group('country-pk');
