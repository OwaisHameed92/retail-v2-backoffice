<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcBillingRequest;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSetupFlow;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use Carbon\CarbonImmutable;

/**
 * The GoCardless client of an instance that collects fees by hand (Pakistan plan P5, AppServiceProvider): Direct
 * Debit is unavailable whatever token is set, so nothing can ever reach GoCardless. `enabled()` is false and every
 * call is refused. The billing code never gets this far on such an instance; this is the last line of defence.
 */
final class NoDirectDebitClient implements GoCardlessClient
{
    public function enabled(): bool
    {
        return false;
    }

    public function environment(): string
    {
        return 'none';
    }

    public function startMandateSetup(string $redirectUri, string $exitUri, array $prefill, array $metadata): GcSetupFlow
    {
        throw self::refused();
    }

    public function billingRequest(string $id): GcBillingRequest
    {
        throw self::refused();
    }

    public function mandate(string $id): GcMandate
    {
        throw self::refused();
    }

    public function createPayment(string $mandateId, int $amountPence, ?string $chargeDate, string $description, array $metadata, string $idempotencyKey): GcPayment
    {
        throw self::refused();
    }

    public function payment(string $id): GcPayment
    {
        throw self::refused();
    }

    public function retryPayment(string $id): GcPayment
    {
        throw self::refused();
    }

    public function paymentsForMandate(string $mandateId, CarbonImmutable $since): array
    {
        throw self::refused();
    }

    public function createSubscription(string $mandateId, int $amountPence, string $intervalUnit, ?string $startDate, string $name, array $metadata, string $idempotencyKey): GcSubscription
    {
        throw self::refused();
    }

    public function subscription(string $id): GcSubscription
    {
        throw self::refused();
    }

    public function updateSubscriptionAmount(string $id, int $amountPence): GcSubscription
    {
        throw self::refused();
    }

    public function pauseSubscription(string $id): GcSubscription
    {
        throw self::refused();
    }

    public function resumeSubscription(string $id): GcSubscription
    {
        throw self::refused();
    }

    public function cancelSubscription(string $id): GcSubscription
    {
        throw self::refused();
    }

    private static function refused(): GoCardlessException
    {
        return new GoCardlessException('Direct Debit is not used on this instance: fees are paid by hand.');
    }
}
