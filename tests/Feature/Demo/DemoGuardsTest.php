<?php

use App\Domain\Billing\GoCardless\Actions\ChangeSubscription;
use App\Domain\Billing\GoCardless\Actions\RetryDirectDebitPayment;
use App\Domain\Billing\GoCardless\Actions\StartMandateSetup;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\DemoSafeGoCardlessClient;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Demo\Billing\BuildDemoBillingBusiness;
use App\Domain\Demo\Billing\DemoBillingScenario;
use App\Domain\Mail\Data\AccountReactivatedData;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Models\EmailLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/* The guards that keep demo businesses away from GoCardless and from mailboxes, one by one. */
beforeEach(function () {
    $this->atLondon('2026-10-05 10:00');
    $this->setVat(true);
    config(['mail.default' => 'array']);
    $this->gc = $this->fakeGoCardless();
});

test('the app resolves GoCardless behind the demo guard', function () {
    app()->forgetInstance(GoCardlessClient::class);

    expect(app(GoCardlessClient::class))->toBeInstanceOf(DemoSafeGoCardlessClient::class);
});

test('the guard refuses demo ids and demo companies before GoCardless is called, and passes the rest', function () {
    $demo = app(BuildDemoBillingBusiness::class)->handle(DemoBillingScenario::SetupMonthly);
    $inner = new FakeGoCardlessClient;
    $client = new DemoSafeGoCardlessClient($inner);
    $mandate = (string) $this->billingAccountOf($demo)->gc_mandate_id;

    $calls = [
        fn () => $client->mandate($mandate),
        fn () => $client->subscription('DEMO-SB-1'),
        fn () => $client->payment('DEMO-PM-1'),
        fn () => $client->retryPayment('DEMO-PM-1'),
        fn () => $client->paymentsForMandate($mandate, CarbonImmutable::now()),
        fn () => $client->cancelSubscription('DEMO-SB-1'),
        fn () => $client->createSubscription($mandate, 1440, 'monthly', null, 'x', [], 'k'),
        fn () => $client->startMandateSetup('https://a.test', 'https://b.test', [], ['company_id' => $demo->id]),
        fn () => $client->createPayment('MD0001', 100, null, 'x', ['company_id' => $demo->id], 'k2'),
    ];

    foreach ($calls as $call) {
        expect($call)->toThrow(GoCardlessException::class, 'demo business');
    }

    expect($inner->calls)->toBe([]);

    $real = $this->payingTenant();
    $client->startMandateSetup('https://a.test', 'https://b.test', [], ['company_id' => $real->id]);
    expect($inner->calls)->toBe(['startMandateSetup']);
});

test('the Direct Debit actions skip or refuse a demo business without calling GoCardless', function () {
    $demo = app(BuildDemoBillingBusiness::class)->handle(DemoBillingScenario::PaymentFailed);
    $failed = GoCardlessPayment::withoutCompanyScope()->where('company_id', $demo->id)->where('status', PaymentStatus::Failed->value)->sole();

    expect(app(SyncSubscription::class)->handle($demo, 'staff'))->toBe('demo')
        ->and(fn () => app(StartMandateSetup::class)->handle($demo))->toThrow(GoCardlessException::class)
        ->and(fn () => app(ChangeSubscription::class)->handle($demo, 'pause'))->toThrow(ValidationException::class)
        ->and(fn () => app(RetryDirectDebitPayment::class)->handle($demo, $failed->id))->toThrow(ValidationException::class)
        ->and($this->gc->calls)->toBe([]);
});

test('the admin buttons answer plainly for a demo business', function () {
    $demo = app(BuildDemoBillingBusiness::class)->handle(DemoBillingScenario::WaitingForDirectDebit);
    $this->actingAs($this->admin(), 'admin');

    $this->post(route('admin.billing.tenants.direct-debit.setup-email', $demo))
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Nothing was sent'));
    $this->post(route('admin.billing.tenants.direct-debit.sync', $demo))
        ->assertSessionHas('success', 'Demo business: nothing is sent to GoCardless.');

    expect($this->gc->calls)->toBe([])
        ->and(EmailLog::query()->where('template', 'direct-debit-setup')->where('status', EmailStatus::Suppressed->value)->count())->toBe(2);
});

test('a retry on a real business goes to GoCardless and the payment is collected again', function () {
    $company = $this->payingTenant();
    $this->billingAccountOf($company)->forceFill(['billing_mode' => 'directDebit', 'gc_mandate_id' => 'MD000777', 'gc_mandate_status' => 'active'])->save();
    $remote = $this->gc->createPayment('MD000777', 3000, '2026-10-01', 'Subscription', [], 'retry-test');
    $this->gc->setPaymentStatus($remote->id, PaymentStatus::Failed);
    $row = new GoCardlessPayment(['gc_payment_id' => $remote->id, 'gc_mandate_id' => 'MD000777', 'kind' => 'subscription', 'amount' => '30.00', 'charge_date' => '2026-10-01', 'status' => PaymentStatus::Failed]);
    $row->company_id = $company->id;
    $row->save();

    $updated = app(RetryDirectDebitPayment::class)->handle($company, $row->id);

    expect($updated->status)->toBe(PaymentStatus::PendingSubmission)
        ->and($this->gc->calls)->toContain('retryPayment')
        ->and(fn () => app(RetryDirectDebitPayment::class)->handle($company, $row->id))->toThrow(ValidationException::class, 'Only a failed payment');
});

test('mail to a demo business or a .invalid address is logged as suppressed, never sent', function () {
    $demo = app(BuildDemoBillingBusiness::class)->handle(DemoBillingScenario::SetupOnlyPaid);
    $sent = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();

    Mail::to('someone@example.com')->queue(new AccountReactivatedMail(new AccountReactivatedData($demo->name, 'Demo Owner', 1, null, $demo->id)));
    Mail::to('nobody@shop.invalid')->send(InvoiceMail::sample());

    expect($sent())->toBe(0)
        ->and(EmailLog::query()->where('to', 'someone@example.com')->sole()->status)->toBe(EmailStatus::Suppressed)
        ->and(EmailLog::query()->where('to', 'nobody@shop.invalid')->sole()->status)->toBe(EmailStatus::Suppressed);
});
