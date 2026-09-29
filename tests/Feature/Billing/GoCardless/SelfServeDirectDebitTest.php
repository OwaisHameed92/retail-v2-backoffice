<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
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
    $this->standardPlan()->forceFill(['price_monthly' => '25.00', 'price_yearly' => '250.00', 'setup_fee' => '199.00'])->save();
});

/** The admin wizard's form (module 1.2) plus the upfront payment fields. */
function onboardingForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Patel News', 'email' => 'hello@patelnews.test', 'status' => 'trial',
        'branch_code' => 'LDS', 'branch_name' => 'Leeds', 'branch_nation' => 'england',
        'tills' => 2, 'owner_name' => 'Raj Patel', 'owner_email' => 'raj@patelnews.test',
    ], $overrides);
}

function onboardedCompany(): Company
{
    return Company::query()->where('name', 'Patel News')->sole();
}

/** A business onboarded through the wizard without an upfront payment: Direct Debit due within 3 days. */
function onboardedTenant(object $test): Company
{
    $test->actingAs($test->admin(AdminRole::Sales), 'admin')->post('/admin/tenants', onboardingForm())->assertSessionHasNoErrors();

    return onboardedCompany();
}

test('the wizard records a cash upfront payment: setup fee invoice paid, trial kept, Direct Debit due in 3 days', function () {
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->post('/admin/tenants', onboardingForm(['upfront_record' => true, 'upfront_amount' => '', 'upfront_method' => 'cash', 'upfront_reference' => 'Receipt 12']))
        ->assertRedirect()->assertSessionHasNoErrors();

    $company = onboardedCompany();
    $account = $this->billingAccountOf($company);
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();

    expect($invoice->kind)->toBe(InvoiceKind::SetupFee)
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->total)->toBe('238.80') // £199 + VAT
        ->and(Payment::withoutCompanyScope()->sole()->method)->toBe(PaymentMethod::Cash)
        ->and(Payment::withoutCompanyScope()->sole()->reference)->toBe('Receipt 12')
        ->and($company->status)->toBe(CompanyStatus::Trial)
        ->and($account->billing_mode)->toBe(BillingMode::DirectDebit)
        ->and($account->setup_fee_method)->toBe(SetupFeeMethod::Manual)
        ->and($account->upfront_amount)->toBe('238.80')
        ->and($account->upfront_method)->toBe(PaymentMethod::Cash)
        ->and($account->mandate_deadline_at->toIso8601String())->toBe(now()->addDays(3)->toImmutable()->toIso8601String());

    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->billingUrl === config('sspos.portal_url').'/app/billing' && $mail->data->directDebitDays === 3);

    // The mandate later does not charge the setup fee again.
    $this->setUpMandate($company);
    expect($this->gc->oneOffPayments())->toBe([]);
});

test('lead approval records a bank transfer at an agreed amount', function () {
    $lead = Lead::factory()->create();

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->post("/admin/leads/{$lead->id}/approve", [
        'shops' => [['name' => 'Leeds', 'code' => 'LDS', 'nation' => 'england', 'tills' => 1]],
        'upfront_record' => true, 'upfront_amount' => '£100', 'upfront_method' => 'bankTransfer',
    ])->assertSessionHasNoErrors();

    $company = $lead->fresh()->company;
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();

    expect($invoice->total)->toBe('120.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::withoutCompanyScope()->sole()->method)->toBe(PaymentMethod::BankTransfer)
        ->and($this->billingAccountOf($company)->setup_fee_override)->toBe('100.00')
        ->and($this->billingAccountOf($company)->billing_mode)->toBe(BillingMode::DirectDebit);
});

test('an upfront payment of £0 records that nothing was due; staff without billing rights cannot record one', function () {
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->post('/admin/tenants', onboardingForm(['upfront_record' => true, 'upfront_amount' => '0', 'upfront_method' => 'cash']))
        ->assertSessionHasNoErrors();
    $account = $this->billingAccountOf(onboardedCompany());

    expect(Invoice::withoutCompanyScope()->count())->toBe(0)
        ->and($account->upfront_amount)->toBe('0.00')
        ->and($account->upfront_recorded_at)->not->toBeNull();

    // Sales can onboard but not take money: the fields are ignored, the fee is left to the Direct Debit.
    $this->actingAs($this->admin(AdminRole::Sales), 'admin')
        ->post('/admin/tenants', onboardingForm(['name' => 'Sales Shop', 'owner_email' => 'sales@shop.test', 'upfront_record' => true, 'upfront_method' => 'cash']))
        ->assertSessionHasNoErrors();
    $sales = $this->billingAccountOf(Company::query()->where('name', 'Sales Shop')->sole());

    expect($sales->upfront_recorded_at)->toBeNull()
        ->and($sales->billing_mode)->toBe(BillingMode::DirectDebit)
        ->and(Payment::withoutCompanyScope()->count())->toBe(0);

    // Billing admins can record it later from the Billing tab, once.
    $company = Company::query()->where('name', 'Sales Shop')->sole();
    $accounts = $this->actingAs($this->admin(AdminRole::Accounts), 'admin');
    $accounts->post(route('admin.billing.tenants.upfront', $company), ['upfront_method' => 'cash'])->assertSessionHasNoErrors();
    $accounts->post(route('admin.billing.tenants.upfront', $company), ['upfront_method' => 'cash'])->assertSessionHasErrors('upfront_amount');
    expect(Payment::withoutCompanyScope()->sole()->amount)->toBe('238.80');
});

test('the owner sees the portal Billing page; roles without billing.view and other businesses do not', function () {
    $company = onboardedTenant($this);
    $other = $this->payingTenant('Other Shop', 1, 'OTH');
    $otherInvoice = $this->issuedFor($other);
    $owner = $this->ownerOf($company);

    $this->actingAs($owner, 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/billing')
        ->where('businessName', 'Patel News')
        ->where('pricing.mode', PricingMode::PerTill->value)
        ->where('pricing.unitsLabel', '2 tills')
        ->where('pricing.unitPrice', '£25.00')
        ->where('pricing.gross', '£60.00')
        ->where('directDebit.canSetUp', true)
        ->where('directDebit.deadline.daysLeft', 3)
        ->where('invoices', []));

    $staff = $this->addMember($company, CompanyRole::Staff);
    $ownInvoice = $this->issuedFor($company);
    $this->actingAs($staff, 'web')->get('/app/billing')->assertForbidden();
    $this->actingAs($staff, 'web')->post('/app/billing/direct-debit')->assertForbidden();
    $this->actingAs($staff, 'web')->get('/app/billing/direct-debit/return')->assertForbidden();
    $this->actingAs($staff, 'web')->get("/app/billing/invoices/{$ownInvoice->id}/pdf")->assertForbidden();
    $this->actingAs($this->addMember($company, CompanyRole::Manager), 'web')->post('/app/billing/direct-debit')->assertForbidden();
    $this->actingAs($this->addMember($company, CompanyRole::Manager), 'web')->get('/app/billing/direct-debit/return')->assertForbidden();

    // The accountant reads Billing but may not set up or finish the Direct Debit (owner only: billing.manage).
    $accountant = $this->addMember($company, CompanyRole::Accountant);
    $this->actingAs($accountant, 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page->where('directDebit.canSetUp', false));
    $this->actingAs($accountant, 'web')->post('/app/billing/direct-debit')->assertForbidden();
    $this->actingAs($accountant, 'web')->get('/app/billing/direct-debit/return')->assertForbidden();
    expect($this->billingAccountOf($company)->gc_billing_request_id)->toBeNull();

    // Company A never sees company B's invoices or PDFs.
    $this->actingAs($owner, 'web')->get("/app/billing/invoices/{$otherInvoice->id}/pdf")->assertNotFound();
    $this->actingAs($this->ownerOf($other), 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page
        ->where('businessName', 'Other Shop')
        ->has('invoices', 1)
        ->where('invoices.0.number', $otherInvoice->number));
    $this->get('/logout');
});

test('guests are sent to the login from every Billing route', function () {
    $company = onboardedTenant($this);
    $invoice = $this->issuedFor($company);

    $this->get('/app/billing')->assertRedirect('/login');
    $this->post('/app/billing/direct-debit')->assertRedirect('/login');
    $this->get('/app/billing/direct-debit/return')->assertRedirect('/login');
    $this->get("/app/billing/invoices/{$invoice->id}/pdf")->assertRedirect('/login');
    expect($this->billingAccountOf($company)->gc_billing_request_id)->toBeNull();
});

test('the banner counts the days left and disappears once the Direct Debit exists', function () {
    $company = onboardedTenant($this);
    $staff = $this->addMember($company, CompanyRole::Staff);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertInertia(fn (Assert $page) => $page
        ->where('billingNotice.daysLeft', 3)->where('billingNotice.passed', false)->where('billingNotice.canSetUp', true));

    $this->atLondon('2026-10-26 12:00');
    $this->actingAs($staff, 'web')->get('/app')->assertInertia(fn (Assert $page) => $page
        ->where('billingNotice.daysLeft', 1)->where('billingNotice.canSetUp', false));

    $this->setUpMandate($company);
    $this->actingAs($staff, 'web')->get('/app')->assertInertia(fn (Assert $page) => $page->where('billingNotice', null));
});

test('the owner sets up Direct Debit from the portal: GoCardless page, back to Billing, finished by return or webhook', function () {
    $second = $this->payingTenant('Webhook Shop', 1, 'WEB');
    $this->billingAccountOf($second)->forceFill(['billing_mode' => BillingMode::DirectDebit])->save();
    $company = onboardedTenant($this);
    $owner = $this->ownerOf($company);

    $response = $this->actingAs($owner, 'web')->post('/app/billing/direct-debit');
    $requestId = (string) $this->billingAccountOf($company)->gc_billing_request_id;

    $response->assertRedirect('https://pay-sandbox.gocardless.test/flow/'.$requestId);
    expect($this->gc->redirects[$requestId])->toBe(route('app.billing.direct-debit.return'));

    // Back before GoCardless finished: "confirming".
    $this->actingAs($owner, 'web')->get('/app/billing/direct-debit/return')->assertRedirect(route('app.billing'))->assertSessionHas('success', fn (string $m) => str_contains($m, 'confirming'));

    // Finished: the return completes it, the subscription starts.
    $this->gc->fulfil($requestId);
    $this->actingAs($owner, 'web')->get('/app/billing/direct-debit/return')->assertSessionHas('success', fn (string $m) => str_contains($m, 'is set up'));

    $account = $this->billingAccountOf($company);
    expect($account->hasUsableMandate())->toBeTrue()
        ->and($this->gc->lastSubscription()->amountPence)->toBe(6000);

    // Pressing the button again is refused.
    $this->actingAs($owner, 'web')->post('/app/billing/direct-debit')->assertSessionHasErrors('status');

    // The webhook path finishes it too (another business).
    $this->actingAs($this->ownerOf($second), 'web')->post('/app/billing/direct-debit');
    $secondRequest = (string) $this->billingAccountOf($second)->gc_billing_request_id;
    $mandate = $this->gc->fulfil($secondRequest);
    $this->webhook([$this->gcEvent('billing_requests', 'fulfilled', ['billing_request' => $secondRequest, 'mandate_request_mandate' => $mandate->id, 'customer' => (string) $mandate->customerId])])->assertOk();
    expect($this->billingAccountOf($second)->hasUsableMandate())->toBeTrue();
});

test('no mandate 3 days after onboarding suspends the business; the owner can still set it up, which lifts it', function () {
    $company = onboardedTenant($this);

    expect($this->runBillingOn('2026-10-27')['noMandateSuspended'])->toBe(0);
    expect($this->runBillingOn('2026-10-28')['noMandateSuspended'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended)
        ->and($this->companyFresh($company)->suspension_reason)->toBe('No Direct Debit set up');
    Mail::assertQueued(AccountSuspendedMail::class, fn (AccountSuspendedMail $mail) => str_contains((string) $mail->data->howToFix, '/app/billing'));
    expect($this->runBillingOn('2026-10-29')['noMandateSuspended'])->toBe(0);

    // Suspended: the portal is on hold, but Billing stays open to the owner.
    $owner = $this->ownerOf($company);
    $this->actingAs($owner, 'web')->get('/app')->assertInertia(fn (Assert $page) => $page->component('app/account-on-hold')->where('directDebitUrl', route('app.billing')));
    $this->actingAs($owner, 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page->component('app/billing')->where('directDebit.deadline.passed', true));

    $this->actingAs($owner, 'web')->post('/app/billing/direct-debit');
    $this->gc->fulfil((string) $this->billingAccountOf($company)->gc_billing_request_id);
    $this->actingAs($owner, 'web')->get('/app/billing/direct-debit/return');

    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Trial);
});

test('nothing recurring (£0) never needs a mandate', function () {
    $this->standardPlan()->forceFill(['price_monthly' => '0.00', 'price_yearly' => '0.00'])->save();
    $company = onboardedTenant($this);

    expect($this->runBillingOn('2026-11-10')['noMandateSuspended'])->toBe(0)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Trial);
    $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertInertia(fn (Assert $page) => $page->where('billingNotice', null));
    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->billingUrl === null);
});

test('per branch, monthly: collected renews the tills, failed ends in suspension through billing:run', function () {
    $this->standardPlan()->forceFill(['setup_fee' => '0.00'])->save();
    $company = $this->directDebitTenant(tills: 2);
    $this->standardPlan()->forceFill(['pricing_mode' => PricingMode::PerBranch, 'price_monthly' => '40.00'])->save();
    $this->setUpMandate($company);
    $subscription = $this->gc->lastSubscription();
    expect($subscription->amountPence)->toBe(4800);

    // 1 Nov collected: invoice with the branch line paid, both tills renewed to 30 Nov.
    $payment = $this->gc->collect($subscription->id, '2026-11-01');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->atLondon('2026-11-04 09:00');
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();

    $invoice = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole()->invoice;
    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->total)->toBe('48.00')
        ->and($invoice->lines->sole()->branch_id)->toBe($this->branchOf($company)->id);
    foreach ($this->licencesOf($company) as $licence) {
        expect($this->licenceFresh($licence)->expires_at->utc()->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));
    }

    // 1 Dec fails: overdue, then suspended 14 days after the due date.
    $failed = $this->gc->collect($subscription->id, '2026-12-01');
    $this->paymentEvent($failed, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->atLondon('2026-12-04 09:00');
    $this->paymentEvent($failed, PaymentStatus::Failed, 'failed')->assertOk();

    expect($this->runBillingOn('2026-12-05')['invoicesOverdue'])->toBe(1)
        ->and($this->runBillingOn('2026-12-16')['companiesSuspended'])->toBe(1)
        ->and($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);
});
