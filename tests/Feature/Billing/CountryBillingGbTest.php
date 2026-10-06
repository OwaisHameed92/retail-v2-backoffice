<?php

use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\Data\TenantBilling;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P5, GB golden tests: the UK portal bills exactly as before the manual-collection (PK) branch was added.
 * Direct Debit onboarding with its deadline, the mandate prompts, GoCardless called through the fake client, the UK
 * payment methods and the billing emails' UK lines.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

/** The rendered text of a mailable, tags and styles removed. */
function gbBillingMailText(object $mail): string
{
    return html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#s', '', $mail->render())));
}

it('is a Direct Debit instance: no manual collection, no PK-only methods or emails', function () {
    expect(ManualCollection::active())->toBeFalse()
        ->and(app(Country::class)->billingCollection())->toBe('gocardless')
        ->and(app(Country::class)->toFrontend())->not->toHaveKey('manualMethods')
        ->and(EmailTemplates::keys())->toBe(array_map(fn (string $class) => $class::templateKey(), EmailTemplates::MAILABLES))
        ->and(EmailTemplates::keys())->not->toContain('payment-reminder')->toContain('direct-debit-setup');
});

it('offers the UK payment methods only', function () {
    $values = fn (array $methods) => array_map(fn (PaymentMethod $method) => $method->value, $methods);

    expect($values(PaymentMethod::manual()))->toBe(['cash', 'card', 'bankTransfer', 'other'])
        ->and($values(PaymentMethod::setupFee()))->toBe(['cash', 'card', 'bankTransfer'])
        ->and(array_column(PaymentMethod::options(), 'value'))->toBe(['cash', 'card', 'bankTransfer', 'other', 'online', 'directDebit'])
        ->and(PaymentMethod::options(manualOnly: true))->toBe([
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'bankTransfer', 'label' => 'Bank transfer'],
            ['value' => 'other', 'label' => 'Other'],
        ])
        ->and(PaymentMethod::setupFeeOptions())->toBe([
            ['value' => 'cash', 'label' => 'Cash'],
            ['value' => 'card', 'label' => 'Card'],
            ['value' => 'bankTransfer', 'label' => 'Bank transfer'],
        ]);

    $company = $this->payingTenant();
    expect(TenantBilling::for($company, true)['options']['methods'])->toBe(PaymentMethod::options(manualOnly: true))
        ->and(TenantBilling::for($company, true)['directDebit'])->not->toHaveKey('manual');

    // A mobile wallet is not a UK method: refused with the UK messages.
    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'jazzCash', 'amount' => '50.00', 'received_on' => '2026-10-24', 'allocation' => 'auto',
    ])->assertSessionHasErrors(['method' => 'Choose cash, bank transfer or other.']);

    expect(fn () => app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::JazzCash)))
        ->toThrow(ValidationException::class, 'Choose cash, card or bank transfer.');
});

it('onboards on Direct Debit with the 3-day mandate deadline, the prompts and GoCardless through the fake client', function () {
    $company = $this->trialTenant('Patel News', 1, '2026-10-30 10:00');
    $this->standardPlan()->forceFill(['setup_fee' => '199.00', 'price_monthly' => '25.00', 'price_yearly' => '0.00', 'billing_type' => null])->save();
    $account = app(OnboardTenantBilling::class)->handle($company->refresh());

    expect($account->isDirectDebit())->toBeTrue()
        ->and($account->mandate_deadline_at?->toDateTimeString())->toBe(CarbonImmutable::now()->addDays(3)->toDateTimeString());

    // The portal: the Direct Debit card, the deadline and the owner's "Set up Direct Debit"; the banner on every page.
    $owner = $this->ownerOf($company);
    $this->actingAs($owner, 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/billing')
        ->where('directDebit.directDebit', true)->where('directDebit.available', true)->where('directDebit.canSetUp', true)
        ->where('directDebit.deadline.daysLeft', 3)
        ->where('status.state', 'waitingForDirectDebit')
        ->where('status.recurring.text', fn (string $text) => str_contains($text, 'by Direct Debit — no Direct Debit set up yet'))
        ->where('billingNotice.daysLeft', 3)->where('billingNotice.canSetUp', true)
        ->missing('manualPayment'));

    // The setup fee by card, then the owner's mandate: GoCardless gets the setup flow and the subscription.
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::Card));
    $this->setUpMandate($company);

    expect($this->gc->calls)->toContain('startMandateSetup')->toContain('createSubscription')
        ->and($this->gc->subscriptions)->toHaveCount(1)
        ->and($this->billingAccountOf($company)->hasLiveSubscription())->toBeTrue();

    // The Direct Debit routes exist: a webhook without a valid signature is refused (498), not missing.
    $this->webhook([], 'not-the-signature')->assertStatus(498);
});

it('runs billing:run with the UK steps only and never sends a payment reminder', function () {
    $company = $this->payingTenant();
    $invoice = $this->issuedFor($company);

    foreach (['2026-10-28', '2026-10-31', '2026-11-04'] as $day) {
        $run = $this->runBillingOn($day);
        expect(array_keys($run))->toBe(['invoicesCreated', 'invoicesOverdue', 'companiesSuspended', 'trialReminders', 'trialEnded', 'noMandateSuspended', 'mandateReminders', 'directDebitReminders', 'setupOnlyLicences']);
    }

    Mail::assertNotQueued(PaymentReminderMail::class);
    expect($this->fresh($invoice)->due_soon_reminded_at)->toBeNull()
        ->and($this->fresh($invoice)->overdue_reminded_at)->toBeNull()
        ->and($this->fresh($invoice)->currency)->toBe('GBP');

    $this->artisan('billing:run')->expectsOutputToContain('Suspended without a Direct Debit')->assertSuccessful();
});

it('keeps the UK billing email lines', function () {
    config(['billing.bank.account_name' => 'Switch & Save Ltd', 'billing.bank.sort_code' => '12-34-56', 'billing.bank.account_number' => '12345678']);
    $company = $this->payingTenant();
    $invoice = $this->issuedFor($company);

    Mail::assertQueued(InvoiceMail::class, function (InvoiceMail $mail) {
        $text = gbBillingMailText($mail);

        return $mail->data->howToPay === null
            && str_contains($text, 'We take cash, or you can pay by bank transfer. Use '.$mail->data->invoiceNumber.' as the reference so we can match your payment.')
            && str_contains($text, 'Bank transfer')
            && str_contains($text, 'Sort code 12-34-56')
            && ! str_contains($text, 'JazzCash');
    });

    expect(InvoiceDocument::bankLines())->toBe(['Switch & Save Ltd', 'Sort code 12-34-56', 'Account 12345678'])
        ->and(InvoiceDocument::for($invoice))->not->toHaveKey('howToPay')
        ->and(view('billing.invoice-pdf', ['doc' => InvoiceDocument::for($invoice), 'logo' => ''])->render())
        ->toContain('We take cash, or pay by bank transfer quoting');

    // Suspension and trial emails: the UK "in cash" wording; the welcome email asks for the Direct Debit.
    app(BillingMailer::class)->suspended($company, 'Invoice INV-000001 unpaid', '50.00', CarbonImmutable::now());
    Mail::assertQueued(AccountSuspendedMail::class, fn (AccountSuspendedMail $mail) => $mail->data->howToFix === null
        && str_contains(gbBillingMailText($mail), 'Pay the amount due of £50.00 in cash'));

    app(BillingMailer::class)->trialReminder($company, CarbonImmutable::now()->addDays(2), 2, 2, '£25.00 per till per month');
    Mail::assertQueued(TrialReminderMail::class, fn (TrialReminderMail $mail) => $mail->data->howToPay === null
        && str_contains(gbBillingMailText($mail), 'We take payment in cash for now.'));

    $trial = $this->trialTenant('Patel News', 1, '2026-10-30 10:00', 'PTL');
    app(LicenceMailer::class)->welcome($trial, $this->ownerOf($trial), []);
    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->directDebitDays === 3
        && str_contains(gbBillingMailText($mail), 'Set up your Direct Debit'));
});

it('keeps the UK seller defaults on GB', function () {
    expect(config('billing.seller.legal_name'))->toBe('Switch & Save Ltd')
        ->and(config('billing.vat.rate'))->toBe('20.00')
        ->and(InvoiceDocument::seller(null))->toBe([
            'name' => 'Switch & Save', 'legalName' => 'Switch & Save Ltd', 'address' => [], 'companyNumber' => null,
            'vatNumber' => null, 'email' => config('billing.seller.email'), 'phone' => trim((string) config('billing.seller.phone')) ?: null,
        ]);
});

it('keeps the UK plan price limit and its message', function () {
    $this->actingAs($this->admin(), 'admin')->post(route('admin.plans.store'), [
        'name' => 'Big', 'code' => 'big', 'description' => null, 'pricing_mode' => 'perTill', 'billing_type' => 'setupAndRecurring',
        'price_monthly' => '30.00', 'price_yearly' => '300.00', 'setup_fee' => '150000', 'trial_days' => 7, 'trial_grace_days' => 3,
        'grace_days' => 7, 'features' => [], 'is_active' => true, 'is_public' => false, 'sort_order' => 10,
    ])->assertSessionHasErrors(['setup_fee' => 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.']);
});
