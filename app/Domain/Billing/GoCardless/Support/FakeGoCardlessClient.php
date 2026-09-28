<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcBillingRequest;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSetupFlow;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use Carbon\CarbonImmutable;

/**
 * In-memory GoCardless for tests (and local demos without a sandbox token): no network. `install()` binds it.
 * Test helpers play the customer's and GoCardless' part: fulfil a billing request, collect a subscription
 * payment, change a payment's or mandate's status. Idempotency keys return the first result, like GoCardless.
 */
final class FakeGoCardlessClient implements GoCardlessClient
{
    /** @var array<string, GcBillingRequest> */
    public array $billingRequests = [];

    /** @var array<string, string> Where each billing request sends the customer back (module 1.13 tests). */
    public array $redirects = [];

    /** @var array<string, GcMandate> */
    public array $mandates = [];

    /** @var array<string, GcPayment> */
    public array $payments = [];

    /** @var array<string, GcSubscription> */
    public array $subscriptions = [];

    /** @var array<string, string> idempotency key => resource id */
    private array $keys = [];

    /** @var list<string> method names called, in order */
    public array $calls = [];

    private int $sequence = 0;

    public bool $enabled = true;

    /** The next call to this method throws GoCardlessException. */
    public ?string $failNext = null;

    public static function install(): self
    {
        $fake = new self;
        app()->instance(GoCardlessClient::class, $fake);

        return $fake;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function environment(): string
    {
        return 'sandbox';
    }

    public function startMandateSetup(string $redirectUri, string $exitUri, array $prefill, array $metadata): GcSetupFlow
    {
        $this->called(__FUNCTION__);
        $id = $this->id('BRQ');
        $this->billingRequests[$id] = new GcBillingRequest($id, 'pending', metadata: $metadata);
        $this->redirects[$id] = $redirectUri;

        return new GcSetupFlow($id, 'https://pay-sandbox.gocardless.test/flow/'.$id, CarbonImmutable::now()->addDays(7));
    }

    public function billingRequest(string $id): GcBillingRequest
    {
        $this->called(__FUNCTION__);

        return $this->billingRequests[$id] ?? throw new GoCardlessException("GoCardless: billing request {$id} not found");
    }

    public function mandate(string $id): GcMandate
    {
        $this->called(__FUNCTION__);

        return $this->mandates[$id] ?? throw new GoCardlessException("GoCardless: mandate {$id} not found");
    }

    public function createPayment(string $mandateId, int $amountPence, ?string $chargeDate, string $description, array $metadata, string $idempotencyKey): GcPayment
    {
        $this->called(__FUNCTION__);

        if (isset($this->keys[$idempotencyKey])) {
            return $this->payments[$this->keys[$idempotencyKey]];
        }

        $id = $this->id('PM');
        $this->payments[$id] = new GcPayment($id, $amountPence, PaymentStatus::PendingSubmission, $chargeDate ?? $this->nextChargeDate($mandateId), $mandateId, null, $description, $metadata);
        $this->keys[$idempotencyKey] = $id;

        return $this->payments[$id];
    }

    public function payment(string $id): GcPayment
    {
        $this->called(__FUNCTION__);

        return $this->payments[$id] ?? throw new GoCardlessException("GoCardless: payment {$id} not found");
    }

    public function paymentsForMandate(string $mandateId, CarbonImmutable $since): array
    {
        $this->called(__FUNCTION__);

        return array_values(array_filter($this->payments, fn (GcPayment $payment) => $payment->mandateId === $mandateId));
    }

    public function createSubscription(string $mandateId, int $amountPence, string $intervalUnit, ?string $startDate, string $name, array $metadata, string $idempotencyKey): GcSubscription
    {
        $this->called(__FUNCTION__);

        if (isset($this->keys[$idempotencyKey])) {
            return $this->subscriptions[$this->keys[$idempotencyKey]];
        }

        $id = $this->id('SB');
        $this->subscriptions[$id] = new GcSubscription($id, $amountPence, $intervalUnit, SubscriptionStatus::Active, $mandateId, $startDate ?? $this->nextChargeDate($mandateId), $metadata);
        $this->keys[$idempotencyKey] = $id;

        return $this->subscriptions[$id];
    }

    public function subscription(string $id): GcSubscription
    {
        $this->called(__FUNCTION__);

        return $this->subscriptions[$id] ?? throw new GoCardlessException("GoCardless: subscription {$id} not found");
    }

    public function updateSubscriptionAmount(string $id, int $amountPence): GcSubscription
    {
        $this->called(__FUNCTION__);

        return $this->replaceSubscription($this->subscription($id), amount: $amountPence);
    }

    public function pauseSubscription(string $id): GcSubscription
    {
        $this->called(__FUNCTION__);

        return $this->replaceSubscription($this->subscription($id), status: SubscriptionStatus::Paused);
    }

    public function resumeSubscription(string $id): GcSubscription
    {
        $this->called(__FUNCTION__);

        return $this->replaceSubscription($this->subscription($id), status: SubscriptionStatus::Active);
    }

    public function cancelSubscription(string $id): GcSubscription
    {
        $this->called(__FUNCTION__);

        return $this->replaceSubscription($this->subscription($id), status: SubscriptionStatus::Cancelled);
    }

    // ---- Test helpers: the customer's and GoCardless' side ----

    /** The customer completes the hosted setup: a customer and a mandate are created, the request is fulfilled. */
    public function fulfil(string $billingRequestId, MandateStatus $status = MandateStatus::PendingSubmission): GcMandate
    {
        $request = $this->billingRequests[$billingRequestId];
        $mandate = new GcMandate($this->id('MD'), $status, $this->id('CU'), CarbonImmutable::now()->addWeekdays(3)->format('Y-m-d'), $request->metadata);
        $this->mandates[$mandate->id] = $mandate;
        $this->billingRequests[$billingRequestId] = new GcBillingRequest($request->id, 'fulfilled', $mandate->customerId, $mandate->id, $request->metadata);

        return $mandate;
    }

    public function setMandateStatus(string $id, MandateStatus $status): GcMandate
    {
        $mandate = $this->mandates[$id];

        return $this->mandates[$id] = new GcMandate($mandate->id, $status, $mandate->customerId, $mandate->nextPossibleChargeDate, $mandate->metadata);
    }

    /** GoCardless creates the next payment of a subscription (at its current amount). */
    public function collect(string $subscriptionId, ?string $chargeDate = null): GcPayment
    {
        $subscription = $this->subscriptions[$subscriptionId];
        $id = $this->id('PM');

        return $this->payments[$id] = new GcPayment($id, $subscription->amountPence, PaymentStatus::PendingSubmission, $chargeDate ?? $subscription->upcomingChargeDate, $subscription->mandateId, $subscription->id, 'Subscription', $subscription->metadata);
    }

    public function setPaymentStatus(string $id, PaymentStatus $status): GcPayment
    {
        $payment = $this->payments[$id];

        return $this->payments[$id] = new GcPayment($payment->id, $payment->amountPence, $status, $payment->chargeDate, $payment->mandateId, $payment->subscriptionId, $payment->description, $payment->metadata);
    }

    public function setSubscription(string $id, ?int $amount = null, ?SubscriptionStatus $status = null, ?string $upcoming = null): GcSubscription
    {
        return $this->replaceSubscription($this->subscriptions[$id], $amount, $status, $upcoming);
    }

    public function lastSubscription(): ?GcSubscription
    {
        return $this->subscriptions === [] ? null : end($this->subscriptions);
    }

    /** @return list<GcPayment> */
    public function oneOffPayments(): array
    {
        return array_values(array_filter($this->payments, fn (GcPayment $payment) => $payment->subscriptionId === null));
    }

    private function replaceSubscription(GcSubscription $old, ?int $amount = null, ?SubscriptionStatus $status = null, ?string $upcoming = null): GcSubscription
    {
        return $this->subscriptions[$old->id] = new GcSubscription($old->id, $amount ?? $old->amountPence, $old->intervalUnit, $status ?? $old->status, $old->mandateId, $upcoming ?? $old->upcomingChargeDate, $old->metadata);
    }

    private function nextChargeDate(string $mandateId): string
    {
        return $this->mandates[$mandateId]->nextPossibleChargeDate ?? CarbonImmutable::now()->addWeekdays(3)->format('Y-m-d');
    }

    private function called(string $method): void
    {
        if (! $this->enabled) {
            throw GoCardlessException::notConfigured();
        }

        if ($this->failNext === $method) {
            $this->failNext = null;

            throw new GoCardlessException('GoCardless: the fake refused '.$method);
        }

        $this->calls[] = $method;
    }

    private function id(string $prefix): string
    {
        return $prefix.str_pad((string) ++$this->sequence, 6, '0', STR_PAD_LEFT);
    }
}
