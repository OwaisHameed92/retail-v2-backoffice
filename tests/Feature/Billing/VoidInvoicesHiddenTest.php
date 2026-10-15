<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Plans\Enums\SetupFeeMode;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Owner 2026-10-15: void invoices stay on record (their numbers are never deleted) but are left out of the business's
 * Billing tab and of the admin invoice list's "All"; the Void filter lists them. Once a setup fee is invoiced, the
 * Direct Debit card shows what the invoices say, not the plan's figure.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

test('void invoices are kept but left out of the Billing tab and the All list', function () {
    $company = $this->payingTenant();
    $void = $this->issuedFor($company);
    $this->voidIt($void);
    $live = $this->issuedFor($company, new NewInvoice(allowOverlap: true));
    $admin = $this->admin(AdminRole::Owner);

    $this->actingAs($admin, 'admin')->get(route('admin.tenants.show', $company))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/show')
            ->has('billing.invoices.data', 1)
            ->where('billing.invoices.data.0.number', $live->number)
            ->where('billing.invoices.total', 1)
            ->where('billing.invoices.void', 1));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.index', ['company' => $company->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', $live->number));

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.index', ['company' => $company->id, 'status' => 'void']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)->where('invoices.data.0.number', $void->number));

    expect($void->fresh()->status->value)->toBe('void');   // still on record
});

test('the Direct Debit card shows the invoiced setup fee, not the plan figure', function () {
    // Istanbul-like: the plan asks £1,200 a till, the business agreed £750 a till (2 tills = £1,500 + VAT).
    $this->standardPlan()->forceFill(['setup_fee' => '1200.00', 'setup_fee_mode' => SetupFeeMode::PerTill])->save();
    $company = $this->payingTenant();
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(setupFee: '1500.00', method: PaymentMethod::Cash));

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->get(route('admin.tenants.show', $company))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.directDebit.setupFee.invoicedTotal', '£1,800.00'));
});
