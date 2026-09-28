<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\GoCardless\Actions\ProcessGoCardlessEvent;
use App\Domain\Billing\GoCardless\Actions\ReconcileGoCardless;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\EventStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Payment;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
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

    $this->company = $this->directDebitTenant('Alpha Stores', 2, 'ALP');
    $this->setUpMandate($this->company);
    $this->subscription = $this->gc->lastSubscription();
});

test('a webhook with a wrong or missing signature is refused and nothing is stored', function () {
    $before = GoCardlessEvent::query()->count();
    $event = $this->gcEvent('payments', 'confirmed', ['payment' => 'PM999999']);

    $this->webhook([$event], 'not-the-signature')->assertStatus(498)->assertJsonPath('code', 'signature.invalid');
    $this->call('POST', '/webhooks/gocardless', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['events' => [$event]]))->assertStatus(498);

    config(['services.gocardless.webhook_secret' => '']);
    $this->webhook([$event])->assertStatus(498);

    expect(GoCardlessEvent::query()->count())->toBe($before);
});

test('every event is stored once: a re-delivery is a no-op', function () {
    $payment = $this->gc->collect($this->subscription->id, '2026-11-01');
    $this->gc->setPaymentStatus($payment->id, PaymentStatus::Confirmed);
    $event = $this->gcEvent('payments', 'confirmed', ['payment' => $payment->id, 'subscription' => $this->subscription->id]);

    $this->webhook([$event])->assertOk()->assertJson(['received' => 1, 'duplicates' => 0]);
    $this->webhook([$event])->assertOk()->assertJson(['received' => 0, 'duplicates' => 1]);

    expect(GoCardlessEvent::query()->where('gc_event_id', $event['id'])->sole()->status)->toBe(EventStatus::Processed)
        ->and(Payment::withoutCompanyScope()->count())->toBe(1);
});

test('events that are not ours or not handled are logged as ignored', function () {
    // Another system on the same GoCardless organisation.
    $this->gc->payments['PM-OTHER'] = new GcPayment('PM-OTHER', 1000, PaymentStatus::Confirmed, '2026-10-20', 'MD-OTHER');

    $this->webhook([
        $this->gcEvent('payments', 'confirmed', ['payment' => 'PM-OTHER', 'mandate' => 'MD-OTHER']),
        $this->gcEvent('payouts', 'paid', ['payout' => 'PO123']),
    ])->assertOk();

    expect(GoCardlessEvent::query()->where('resource_type', 'payouts')->sole()->status)->toBe(EventStatus::Ignored)
        ->and(GoCardlessEvent::query()->where('resource_type', 'payments')->sole()->status)->toBe(EventStatus::Ignored)
        ->and(GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', 'PM-OTHER')->exists())->toBeFalse();
});

test('a failed event is kept and replayed by the reconcile, safely', function () {
    $payment = $this->gc->collect($this->subscription->id, '2026-11-01');
    $this->gc->setPaymentStatus($payment->id, PaymentStatus::Confirmed);
    $this->gc->failNext = 'payment';

    $this->webhook([$this->gcEvent('payments', 'confirmed', ['payment' => $payment->id])])->assertOk();

    $event = GoCardlessEvent::query()->where('resource_type', 'payments')->sole();
    expect($event->status)->toBe(EventStatus::Failed)
        ->and(Payment::withoutCompanyScope()->count())->toBe(0);

    $report = app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());

    expect($event->refresh()->status)->toBe(EventStatus::Processed)
        ->and($report['errors'])->toBe([])
        ->and(Payment::withoutCompanyScope()->count())->toBe(1);

    // Replaying a processed event by force changes nothing.
    app(ProcessGoCardlessEvent::class)->handle($event, force: true);
    expect(Payment::withoutCompanyScope()->count())->toBe(1);
});

test('the reconcile heals a missed webhook and amount drift; a dry run changes nothing', function () {
    // GoCardless collected a payment we never heard about, and its subscription amount was changed there.
    $payment = $this->gc->collect($this->subscription->id, '2026-11-01');
    $this->gc->setPaymentStatus($payment->id, PaymentStatus::PaidOut);
    $this->gc->setSubscription($this->subscription->id, amount: 1234);

    $dry = app(ReconcileGoCardless::class)->handle(CarbonImmutable::now(), dryRun: true);
    expect($dry['fixes'])->not->toBeEmpty()
        ->and(GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->exists())->toBeFalse();

    $this->artisan('billing:reconcile-gocardless')->assertSuccessful();

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
    expect($row->status)->toBe(PaymentStatus::PaidOut)
        ->and($row->payment_id)->not->toBeNull()
        ->and($this->fresh($row->invoice)->status->value)->toBe('paid')
        ->and($this->gc->subscriptions[$this->subscription->id]->amountPence)->toBe(6000)
        ->and($this->billingAccountOf($this->company)->gc_reconciled_at)->not->toBeNull();

    // Nothing left to fix.
    expect(app(ReconcileGoCardless::class)->handle(CarbonImmutable::now())['fixes'])->toBe([]);
});

test('without a GoCardless token the reconcile does nothing', function () {
    $this->gc->enabled = false;

    expect(app(ReconcileGoCardless::class)->handle(CarbonImmutable::now()))->toBe(['checked' => 0, 'fixes' => [], 'errors' => []]);
});

test('Direct Debit rows only show to their own company', function () {
    $bravo = $this->directDebitTenant('Bravo Mart', 1, 'BRV');
    $this->setUpMandate($bravo);
    $this->standardPlan();

    // Each has a subscription payment.
    foreach ([$this->company, $bravo] as $company) {
        $subscription = $this->gc->subscriptions[$this->billingAccountOf($company)->gc_subscription_id];
        $this->paymentEvent($this->gc->collect($subscription->id, '2026-11-01'), PaymentStatus::PendingSubmission, 'created')->assertOk();
    }

    foreach ([$this->company, $bravo] as $company) {
        $rows = app(CurrentCompany::class)->runAs($company, fn () => GoCardlessPayment::query()->get());

        expect($rows)->toHaveCount(1)->and($rows->first()->company_id)->toBe($company->id);
    }

    // A Bravo payment event never touches Alpha.
    $alphaRow = GoCardlessPayment::withoutCompanyScope()->where('company_id', $this->company->id)->sole();
    expect($alphaRow->invoice->company_id)->toBe($this->company->id);
});

test('the Billing tab and overview show Direct Debit to billing admins', function () {
    $this->paymentEvent($this->gc->collect($this->subscription->id, '2026-11-01'), PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->actingAs($this->admin(AdminRole::Accounts), 'admin');

    $this->get(route('admin.tenants.show', ['company' => $this->company, 'tab' => 'billing']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('billing.directDebit.mode', 'directDebit')
            ->where('billing.directDebit.mandate.usable', true)
            ->where('billing.directDebit.subscription.amount', '£60.00')
            ->where('billing.directDebit.subscription.inStep', true)
            ->has('billing.directDebit.payments.data', 1)
            ->etc());

    $this->get(route('admin.billing.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('directDebit.activeMandates', 1)
            ->where('directDebit.upcoming.count', 1)
            ->where('directDebit.upcoming.amount', '£60.00')
            ->where('directDebit.failed.count', 0)
            ->etc());
});
