<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\PortalBilling;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Actions\CollectSetupFeeByDirectDebit;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Owner 2026-11-05, setup fee by Direct Debit: the default stays by hand and nothing changes for a business without
 * the option (legacy `setup_fee_method = directDebit` included); only billing.manage admins on a UK Direct Debit
 * instance can switch it on; switching on later collects the unpaid instalments only. Also the red box wording on My
 * subscription while the business waits for its Direct Debit.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-11-05 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->admin = $this->admin();
});

function guardShop(object $test, string $name, string $code, SetupFeeMethod $method = SetupFeeMethod::Manual): Company
{
    return $test->directDebitTenant($name, tills: 1, code: $code, setupFee: '950.00', instalments: 3, method: $method);
}

function settingsPut(object $test, Company $company, array $extra = [], AdminRole $role = AdminRole::Owner): TestResponse
{
    $admin = $role === AdminRole::Owner ? $test->admin : $test->admin($role);

    return $test->actingAs($admin, 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => '950.00', 'setup_fee_instalments' => 3, ...$extra,
    ]);
}

/** @return list<Invoice> */
function guardInvoices(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->orderBy('due_date')->get()->all();
}

test('with the option off (the default) the setup fee is invoiced and emailed exactly as before, never on the mandate', function () {
    $company = guardShop($this, 'Off Stores', 'OFF');
    $this->setUpMandate($company);

    app(ChargeSetupFee::class)->handle($company);
    foreach (['2026-11-06', '2026-11-25', '2026-12-26'] as $day) {
        $this->runBillingOn($day);
    }

    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeFalse()
        ->and($this->gc->calls)->not->toContain('createPayment')
        ->and(GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->exists())->toBeFalse();
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => ! $mail->data->directDebit && $mail->data->directDebitOn === null
        && str_contains(html_entity_decode(strip_tags($mail->render())), 'We take cash, or you can pay by bank transfer'));

    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.byDirectDebit', false)
        ->where('billing.directDebit.setupFee.collectedByDirectDebit', false)
        ->where('billing.directDebit.setupFee.parts.0.state', 'byHand'));
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page
        ->where('upfront.byDirectDebit', false)->where('setupFee.method', null)->missing('setupFee.parts.0.directDebit'));
});

test('a legacy setup_fee_method of directDebit never collects anything', function () {
    $company = guardShop($this, 'Abdul Latif Laptop', 'ALL', SetupFeeMethod::DirectDebit);
    $this->setUpMandate($company);

    // Payment settings saved without the new field (an older form) keeps the option off.
    settingsPut($this, $company)->assertSessionHasNoErrors();
    app(ChargeSetupFee::class)->handle($company);
    $this->runBillingOn('2026-11-06');

    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeFalse()
        ->and(app(CollectSetupFeeByDirectDebit::class)->handle($company, email: true))->toBe([])
        ->and($this->gc->calls)->not->toContain('createPayment');
});

test('switched on after invoicing: the unpaid instalments coming due are collected, one paid by hand never is', function () {
    $company = guardShop($this, 'Late Switch', 'LTS');
    $this->setUpMandate($company);
    app(ChargeSetupFee::class)->handle($company);
    [$first, $second, $third] = guardInvoices($company);

    // The first instalment is paid by hand (Record setup fee payment).
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'bankTransfer', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();
    expect($this->fresh($first)->status)->toBe(InvoiceStatus::Paid);

    $this->atLondon('2026-11-28 10:00');
    Mail::fake();
    settingsPut($this, $company, ['setup_fee_by_direct_debit' => true])->assertSessionHasNoErrors();

    $payments = $this->gc->oneOffPayments();
    expect($payments)->toHaveCount(1)
        ->and($payments[0]->metadata['invoice_id'])->toBe($second->id)
        ->and($payments[0]->chargeDate)->toBe('2026-12-05')
        ->and($payments[0]->amountPence)->toBe(38000);
    // The customer was told "cash or bank transfer" before: the open instalments are emailed again with their day.
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $second->number && $mail->data->directDebitOn?->format('Y-m-d') === '2026-12-05');
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $third->number && $mail->data->directDebitOn?->format('Y-m-d') === '2027-01-05');
    Mail::assertNotQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $first->number);

    // Switched off: nothing new is made; the payment already with GoCardless stays and is shown.
    settingsPut($this, $company, ['setup_fee_by_direct_debit' => false])->assertSessionHasNoErrors();
    $this->runBillingOn('2026-12-27');

    expect($this->gc->oneOffPayments())->toHaveCount(1)
        ->and($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeFalse();
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.parts.0.state', 'paid')
        ->where('billing.directDebit.setupFee.parts.1.state', 'scheduled')
        ->where('billing.directDebit.setupFee.parts.2.state', 'byHand'));
});

test('only billing.manage admins can switch it, and only for a Direct Debit business with GoCardless connected', function () {
    $company = guardShop($this, 'Guarded Shop', 'GRD');

    foreach ([AdminRole::Sales, AdminRole::Support] as $role) {
        settingsPut($this, $company, ['setup_fee_by_direct_debit' => true], $role)->assertForbidden();
    }

    settingsPut($this, $company, ['billing_mode' => 'upfrontCash', 'setup_fee_by_direct_debit' => true])
        ->assertSessionHasErrors(['setup_fee_by_direct_debit' => 'Only a business that pays by Direct Debit can have its setup fee collected by Direct Debit.']);

    $this->gc->enabled = false;
    settingsPut($this, $company, ['setup_fee_by_direct_debit' => true])
        ->assertSessionHasErrors(['setup_fee_by_direct_debit' => 'GoCardless is not connected, so the setup fee cannot be collected by Direct Debit.']);

    $this->gc->enabled = true;
    settingsPut($this, $company, ['setup_fee_by_direct_debit' => true], AdminRole::Accounts)->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeTrue();

    // Moving the business off Direct Debit turns it off.
    settingsPut($this, $company, ['billing_mode' => 'upfrontCash'])->assertSessionHasNoErrors();
    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeFalse();
});

test('a manual-collection (Pakistan) instance refuses it and never calls GoCardless', function () {
    $company = $this->payingTenant('Lahore Mart', 1, 'LHR');
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);

    settingsPut($this, $company, ['billing_mode' => 'upfrontCash', 'setup_fee_by_direct_debit' => true])
        ->assertSessionHasErrors(['setup_fee_by_direct_debit' => 'The setup fee cannot be collected by Direct Debit here: every invoice is paid by hand.']);

    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeFalse()
        ->and($this->gc->calls)->toBe([]);
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.directDebitAvailable', false));
})->group('country-pk');

test('another business is untouched', function () {
    $mine = guardShop($this, 'S & S Store', 'SNS');
    $other = guardShop($this, 'Other Shop', 'OTH');
    $this->setUpMandate($mine);
    $this->setUpMandate($other);

    settingsPut($this, $mine, ['setup_fee_by_direct_debit' => true])->assertSessionHasNoErrors();
    app(ChargeSetupFee::class)->handle($mine);
    app(ChargeSetupFee::class)->handle($other);
    $this->runBillingOn('2026-11-25');

    expect(collect($this->gc->oneOffPayments())->pluck('metadata.company_id')->unique()->values()->all())->toBe([$mine->id])
        ->and(GoCardlessPayment::withoutCompanyScope()->where('company_id', $other->id)->exists())->toBeFalse()
        ->and($this->billingAccountOf($other)->setup_fee_by_direct_debit)->toBeFalse();
});

test('the red box says when the tills lock while the business only waits, and "are locked" once suspended', function () {
    $company = $this->directDebitTenant('Waiting Shop', tills: 1, code: 'WAT', method: SetupFeeMethod::Manual);
    $account = $this->billingAccountOf($company);
    $account->billing_mode = BillingMode::DirectDebit;
    $account->mandate_deadline_at = CarbonImmutable::parse('2026-11-05 09:00', 'Europe/London');
    $account->mandate_reminder_for = $account->mandate_deadline_at; // the owner was reminded: the deadline counts
    $account->save();

    $deadline = app(CurrentCompany::class)->runAs($company->refresh(), fn () => PortalBilling::for($company)['directDebit']['deadline']);
    expect($deadline)->toMatchArray(['passed' => true, 'locked' => false, 'lockOn' => '2026-11-06']);

    // billing:run the next morning suspends the business: now the tills are locked.
    $this->runBillingOn('2026-11-06');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('directDebit.deadline.passed', true)->where('directDebit.deadline.locked', true)->where('directDebit.deadline.lockOn', null));
});
