<?php

namespace App\Domain\Billing\GoCardless\Contracts;

use App\Domain\Billing\GoCardless\Data\GcBillingRequest;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSetupFlow;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use Carbon\CarbonImmutable;

/**
 * The GoCardless calls billing makes. `SdkGoCardlessClient` talks to GoCardless (sandbox or live); tests bind
 * `FakeGoCardlessClient` (no network). Amounts are pence. Every method throws GoCardlessException when
 * GoCardless is not configured or refuses the request. Creates take an idempotency key, so a retried call
 * returns the first result instead of charging twice.
 */
interface GoCardlessClient
{
    public function enabled(): bool;

    /** "sandbox" or "live". */
    public function environment(): string;

    /**
     * Starts a hosted Bacs Direct Debit setup (billing request + flow).
     *
     * @param  array<string, string>  $prefill  email, given_name, family_name, company_name
     * @param  array<string, string>  $metadata  up to 3 keys
     *
     * @throws GoCardlessException
     */
    public function startMandateSetup(string $redirectUri, string $exitUri, array $prefill, array $metadata): GcSetupFlow;

    /** @throws GoCardlessException */
    public function billingRequest(string $id): GcBillingRequest;

    /** @throws GoCardlessException */
    public function mandate(string $id): GcMandate;

    /**
     * A one-off payment on the mandate, charged on `$chargeDate` (Y-m-d) or as soon as possible when null.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws GoCardlessException
     */
    public function createPayment(string $mandateId, int $amountPence, ?string $chargeDate, string $description, array $metadata, string $idempotencyKey): GcPayment;

    /** @throws GoCardlessException */
    public function payment(string $id): GcPayment;

    /** Asks GoCardless to collect a failed payment again (it picks the date). @throws GoCardlessException */
    public function retryPayment(string $id): GcPayment;

    /**
     * Payments on a mandate created since `$since` (for the daily reconcile).
     *
     * @return list<GcPayment>
     *
     * @throws GoCardlessException
     */
    public function paymentsForMandate(string $mandateId, CarbonImmutable $since): array;

    /**
     * @param  'monthly'|'yearly'  $intervalUnit
     * @param  array<string, string>  $metadata
     *
     * @throws GoCardlessException
     */
    public function createSubscription(string $mandateId, int $amountPence, string $intervalUnit, ?string $startDate, string $name, array $metadata, string $idempotencyKey): GcSubscription;

    /** @throws GoCardlessException */
    public function subscription(string $id): GcSubscription;

    /** New amount from the next payment GoCardless has not created yet. @throws GoCardlessException */
    public function updateSubscriptionAmount(string $id, int $amountPence): GcSubscription;

    /** @throws GoCardlessException */
    public function pauseSubscription(string $id): GcSubscription;

    /** @throws GoCardlessException */
    public function resumeSubscription(string $id): GcSubscription;

    /** @throws GoCardlessException */
    public function cancelSubscription(string $id): GcSubscription;
}
