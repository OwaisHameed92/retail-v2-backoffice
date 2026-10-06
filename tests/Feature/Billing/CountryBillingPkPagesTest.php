<?php

use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Mail\Mailables\AdminSubscriptionRequestMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P5, the screens and texts of a manual-collection instance: the portal Billing page shows how to pay
 * by hand (bank account, JazzCash, Easypaisa from BILLING_PAY_*), admin screens have no GoCardless parts, the Direct
 * Debit routes do not exist, emails and invoices carry no Direct Debit or UK bank wording, and PKR plans validate.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/** A Pakistan instance with its "how to pay" settings (empty values hide a method). */
function pkPagesInstance(object $test, array $pay = []): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    config([
        'billing.vat.enabled' => false,
        'billing.manual.pay' => $pay + [
            'bank_name' => 'Meezan Bank', 'bank_account_title' => 'Switch & Save', 'bank_iban' => 'PK36MEZN0000000000000001',
            'jazzcash' => '0300 1234567', 'easypaisa' => '0345 7654321',
        ],
    ]);
    $test->gc = FakeGoCardlessClient::install();
    $test->travelTo(CarbonImmutable::parse('2026-10-25 10:00', 'Asia/Karachi'));
}

/** A Lahore shop paid to 31 Oct with its November invoice issued (Rs 5,000, due 1 Nov). */
function pkShopWithInvoice(object $test): Company
{
    $company = $test->payingTenant('Lahore Mart', 2, 'LHR');
    $test->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();
    $test->runBilling();

    return $company->refresh();
}

function pkMailText(object $mail): string
{
    return html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#s', '', $mail->render())));
}

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

it('shows the portal Billing page with what is owed and how to pay, without Direct Debit', function () {
    pkPagesInstance($this);
    $company = pkShopWithInvoice($this);

    $response = $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('app/billing')
        ->where('manualPayment.amountDue', 'Rs 5,000')->where('manualPayment.hasAmountDue', true)
        ->where('manualPayment.next.balance', 'Rs 5,000')->where('manualPayment.next.dueDate', '2026-11-01')
        ->where('manualPayment.methodsText', 'bank transfer, JazzCash, Easypaisa or cash')
        ->where('manualPayment.bank', ['Meezan Bank', 'Account title Switch & Save', 'IBAN PK36MEZN0000000000000001'])
        ->where('manualPayment.jazzCash', '0300 1234567')->where('manualPayment.easypaisa', '0345 7654321')
        ->where('directDebit.available', false)->where('directDebit.canSetUp', false)->where('directDebit.deadline', null)
        ->where('billingNotice', null)
        ->where('country.billingCollection', 'manual')
        ->has('invoices', 1));

    $props = (string) json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain('Direct Debit')->not->toContain('GoCardless')->not->toContain('Sort code')->not->toContain('£')
        ->and($this->gc->calls)->toBe([]);
})->group('country-pk');

it('hides each payment method that is not set up', function () {
    pkPagesInstance($this, ['bank_name' => '', 'bank_account_title' => '', 'bank_iban' => '', 'easypaisa' => '']);
    $company = pkShopWithInvoice($this);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('manualPayment.bank', [])->where('manualPayment.jazzCash', '0300 1234567')->where('manualPayment.easypaisa', null));

    expect(InvoiceDocument::bankLines())->toBe(['JazzCash 0300 1234567']);
})->group('country-pk');

it('has no Direct Debit routes', function () {
    pkPagesInstance($this);
    $company = pkShopWithInvoice($this);
    $owner = $this->ownerOf($company);

    $this->actingAs($owner, 'web')->post('/app/billing/direct-debit')->assertNotFound();
    $this->actingAs($owner, 'web')->get('/app/billing/direct-debit/return')->assertNotFound();
    $this->postJson('/webhooks/gocardless', ['events' => []])->assertNotFound();
    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.direct-debit.setup-email', $company))->assertNotFound();
    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.direct-debit.sync', $company))->assertNotFound();
    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.direct-debit.subscription', [$company, 'pause']))->assertNotFound();

    expect($this->gc->calls)->toBe([]);
})->group('country-pk');

it('shows admin billing screens without GoCardless parts and with the profile methods', function () {
    pkPagesInstance($this);
    $company = pkShopWithInvoice($this);
    $admin = $this->actingAs($this->admin(), 'admin');

    $admin->get(route('admin.tenants.show', $company))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.enabled', false)->where('billing.directDebit.environment', 'none')
        ->where('billing.directDebit.manual.methodsText', 'bank transfer, JazzCash, Easypaisa or cash')
        ->where('billing.directDebit.options.modes', [['value' => 'upfrontCash', 'label' => 'By hand (bank transfer, JazzCash, Easypaisa or cash)']])
        ->where('billing.options.methods.1', ['value' => 'jazzCash', 'label' => 'JazzCash'])
        ->where('billing.status.recurring.text', 'Rs 5,000 a month (2 tills × Rs 2,500) — an invoice each month, paid by bank transfer, JazzCash, Easypaisa or cash')
        ->where('billing.status.next.text', fn (string $text) => str_starts_with($text, 'Invoice INV-') && str_contains($text, 'is due on 1 Nov 2026.')));

    $admin->get(route('admin.billing.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('directDebit', null)->where('settings.autoIssue', true));

    $admin->get(route('admin.billing.payments.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('manualMethods', [
            ['value' => 'bankTransfer', 'label' => 'Bank transfer'],
            ['value' => 'jazzCash', 'label' => 'JazzCash'],
            ['value' => 'easypaisa', 'label' => 'Easypaisa'],
            ['value' => 'cash', 'label' => 'Cash'],
        ]));

    $keys = EmailTemplates::keys();
    expect($keys)->toContain('payment-reminder')->not->toContain('direct-debit-setup')->not->toContain('direct-debit-failed')
        ->not->toContain('direct-debit-cancelled')
        ->and(array_search('payment-reminder', $keys, true))->toBe(array_search('invoice', $keys, true) + 1)
        ->and($this->gc->calls)->toBe([]);
})->group('country-pk');

it('writes invoices and billing emails without Direct Debit or UK bank lines', function () {
    pkPagesInstance($this);
    $company = pkShopWithInvoice($this);
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();

    $pdf = view('billing.invoice-pdf', ['doc' => InvoiceDocument::for($invoice), 'logo' => ''])->render();
    expect($pdf)->toContain('Pay by bank transfer, JazzCash, Easypaisa or cash, quoting '.$invoice->number.' as the reference.')
        ->toContain('IBAN PK36MEZN0000000000000001')->toContain('JazzCash 0300 1234567')
        ->not->toContain('Sort code')->not->toContain('We take cash')->not->toContain('£');

    Mail::assertQueued(InvoiceMail::class, function (InvoiceMail $mail) {
        $text = pkMailText($mail);

        return str_contains($text, 'Pay by bank transfer, JazzCash, Easypaisa or cash') && str_contains($text, 'Pay to')
            && ! str_contains($text, 'Direct Debit') && ! str_contains($text, 'Sort code') && ! str_contains($text, '£');
    });

    $samples = [pkMailText(PaymentReminderMail::sample()), pkMailText(InvoiceMail::sample())];
    expect($samples[0])->toContain('is due on')->toContain('JazzCash 0300 1234567')->toContain('Rs 7,500')
        ->and($samples[1])->toContain('IBAN PK36MEZN0000000000000000')->not->toContain('Sort code');

    app(BillingMailer::class)->trialReminder($company, CarbonImmutable::now()->addDays(2), 2, 2, 'Rs 2,500 per till per month');
    app(LicenceMailer::class)->welcome($company, $this->ownerOf($company), []);
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => str_contains(pkMailText($mail), 'Pay it by bank transfer, JazzCash, Easypaisa or cash'));
    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->billingUrl === null
        && ! str_contains(pkMailText($mail), 'Direct Debit'));

    foreach ([...$samples, ...Mail::queued(PaymentReminderMail::class)->map(fn ($mail) => pkMailText($mail))->all()] as $text) {
        expect($text)->not->toContain('Direct Debit')->not->toContain('GoCardless')->not->toContain('£');
    }
})->group('country-pk');

it('takes a cancellation request without Direct Debit wording and has no bank account change to ask for', function () {
    pkPagesInstance($this);
    $company = pkShopWithInvoice($this);
    $owner = $this->ownerOf($company);

    $this->actingAs($owner, 'web')->post('/app/billing/requests', ['kind' => 'changeBank'])->assertSessionHasErrors('kind');
    $this->actingAs($owner, 'web')->post('/app/billing/requests', ['kind' => 'cancel', 'message' => 'Moving to Karachi', 'confirm' => true, 'phone' => '0300 1234567'])
        ->assertSessionHasNoErrors();

    Mail::assertQueued(AdminSubscriptionRequestMail::class, fn (AdminSubscriptionRequestMail $mail) => str_contains(pkMailText($mail), 'the business cannot cancel its tills itself.')
        && ! str_contains(pkMailText($mail), 'Direct Debit'));
})->group('country-pk');

/** Our seller details as a PK instance's .env leaves them before registration: only the trading name. */
function pkSellerUnset(): void
{
    config([
        'billing.seller' => ['name' => 'Switch & Save', 'legal_name' => '', 'address' => '', 'company_number' => '', 'registered_in' => '',
            'email' => '', 'phone' => '', 'ntn' => '', 'strn' => ''],
        'billing.vat.number' => '',
    ]);
}

it('shows only the trading name as the seller while nothing else is set, never the UK company', function () {
    pkPagesInstance($this);
    pkSellerUnset();
    $company = pkShopWithInvoice($this);
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();
    $doc = InvoiceDocument::for($invoice);

    expect($doc['seller'])->toBe(['name' => 'Switch & Save', 'legalName' => 'Switch & Save', 'address' => [], 'companyNumber' => null,
        'vatNumber' => null, 'email' => null, 'phone' => null, 'strn' => null]);

    $html = view('billing.invoice-pdf', ['doc' => $doc, 'logo' => ''])->render();
    expect($html)->toContain('Switch &amp; Save')
        ->not->toContain('Switch &amp; Save Ltd')->not->toContain('England')->not->toContain('Registered in')->not->toContain('company no')
        ->not->toContain('Companies House')->not->toContain('VAT no.')->not->toContain('NTN')->not->toContain('STRN')
        ->not->toContain('.co.uk')->not->toContain('£');

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => ! str_contains(pkMailText($mail), 'Switch & Save Ltd')
        && ! str_contains(pkMailText($mail), 'England') && ! str_contains(pkMailText($mail), 'Registered in'));
})->group('country-pk');

it('prints our NTN and STRN, labelled, once they are set', function () {
    pkPagesInstance($this);
    pkSellerUnset();
    config(['billing.seller.ntn' => '1234567-8', 'billing.seller.strn' => '1700123456789', 'billing.seller.address' => 'Office 4, Gulberg III, Lahore']);
    $company = pkShopWithInvoice($this);
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();
    $html = view('billing.invoice-pdf', ['doc' => InvoiceDocument::for($invoice), 'logo' => ''])->render();

    expect($html)->toContain('NTN 1234567-8')->toContain('STRN 1700123456789')->toContain('Office 4')
        ->not->toContain('Registered in')->not->toContain('England')->not->toContain('VAT no.');
})->group('country-pk');

it('creates PKR plans with rupee amounts above the UK limit', function () {
    pkPagesInstance($this);

    $this->actingAs($this->admin(), 'admin')->post(route('admin.plans.store'), [
        'name' => 'Karobar', 'code' => 'karobar', 'description' => null, 'pricing_mode' => 'perTill', 'billing_type' => 'setupAndRecurring',
        'price_monthly' => 'Rs 2,500', 'price_yearly' => '25000', 'setup_fee' => 'Rs 1,50,000', 'trial_days' => 7, 'trial_grace_days' => 3,
        'grace_days' => 7, 'features' => [], 'is_active' => true, 'is_public' => false, 'sort_order' => 10,
    ])->assertSessionHasNoErrors();

    $plan = Plan::query()->where('code', 'karobar')->sole();
    expect($plan->currency)->toBe('PKR')->and($plan->setup_fee)->toBe('150000.00')->and($plan->price_monthly)->toBe('2500.00');

    $this->actingAs($this->admin(), 'admin')->post(route('admin.plans.store'), [
        'name' => 'Odd', 'code' => 'odd', 'description' => null, 'pricing_mode' => 'perTill', 'billing_type' => 'recurringOnly',
        'price_monthly' => '12.345', 'price_yearly' => '0', 'setup_fee' => '0', 'trial_days' => 7, 'trial_grace_days' => 3,
        'grace_days' => 7, 'features' => [], 'is_active' => true, 'is_public' => false, 'sort_order' => 10,
    ])->assertSessionHasErrors(['price_monthly' => 'Enter an amount in rupees with up to 2 decimal places, for example 30 or 29.99.']);
})->group('country-pk');
