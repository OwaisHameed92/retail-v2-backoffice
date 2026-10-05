<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Data\GcBillingRequest;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSetupFlow;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Demo\Support\DemoBusinesses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The GoCardless client the app uses (AppServiceProvider): the real one behind a last line of defence for demo
 * businesses (`demo:billing`). Any call on a demo id (`DEMO-…`) or for a demo company (metadata `company_id`) is
 * refused with a GoCardlessException before it reaches GoCardless, so a demo business can sit on a server with LIVE
 * GoCardless keys. The Direct Debit actions already skip demo businesses; this catches anything that slips past.
 */
final class DemoSafeGoCardlessClient implements GoCardlessClient
{
    public function __construct(private readonly GoCardlessClient $inner) {}

    public function enabled(): bool
    {
        return $this->inner->enabled();
    }

    public function environment(): string
    {
        return $this->inner->environment();
    }

    public function startMandateSetup(string $redirectUri, string $exitUri, array $prefill, array $metadata): GcSetupFlow
    {
        $this->guard(null, $metadata, 'startMandateSetup');

        return $this->inner->startMandateSetup($redirectUri, $exitUri, $prefill, $metadata);
    }

    public function billingRequest(string $id): GcBillingRequest
    {
        $this->guard($id, [], 'billingRequest');

        return $this->inner->billingRequest($id);
    }

    public function mandate(string $id): GcMandate
    {
        $this->guard($id, [], 'mandate');

        return $this->inner->mandate($id);
    }

    public function createPayment(string $mandateId, int $amountPence, ?string $chargeDate, string $description, array $metadata, string $idempotencyKey): GcPayment
    {
        $this->guard($mandateId, $metadata, 'createPayment');

        return $this->inner->createPayment($mandateId, $amountPence, $chargeDate, $description, $metadata, $idempotencyKey);
    }

    public function payment(string $id): GcPayment
    {
        $this->guard($id, [], 'payment');

        return $this->inner->payment($id);
    }

    public function retryPayment(string $id): GcPayment
    {
        $this->guard($id, [], 'retryPayment');

        return $this->inner->retryPayment($id);
    }

    public function paymentsForMandate(string $mandateId, CarbonImmutable $since): array
    {
        $this->guard($mandateId, [], 'paymentsForMandate');

        return $this->inner->paymentsForMandate($mandateId, $since);
    }

    public function createSubscription(string $mandateId, int $amountPence, string $intervalUnit, ?string $startDate, string $name, array $metadata, string $idempotencyKey): GcSubscription
    {
        $this->guard($mandateId, $metadata, 'createSubscription');

        return $this->inner->createSubscription($mandateId, $amountPence, $intervalUnit, $startDate, $name, $metadata, $idempotencyKey);
    }

    public function subscription(string $id): GcSubscription
    {
        $this->guard($id, [], 'subscription');

        return $this->inner->subscription($id);
    }

    public function updateSubscriptionAmount(string $id, int $amountPence): GcSubscription
    {
        $this->guard($id, [], 'updateSubscriptionAmount');

        return $this->inner->updateSubscriptionAmount($id, $amountPence);
    }

    public function pauseSubscription(string $id): GcSubscription
    {
        $this->guard($id, [], 'pauseSubscription');

        return $this->inner->pauseSubscription($id);
    }

    public function resumeSubscription(string $id): GcSubscription
    {
        $this->guard($id, [], 'resumeSubscription');

        return $this->inner->resumeSubscription($id);
    }

    public function cancelSubscription(string $id): GcSubscription
    {
        $this->guard($id, [], 'cancelSubscription');

        return $this->inner->cancelSubscription($id);
    }

    /**
     * @param  array<string, string>  $metadata
     *
     * @throws GoCardlessException
     */
    private function guard(?string $id, array $metadata, string $method): void
    {
        $companyId = $metadata['company_id'] ?? null;

        if (DemoBusinesses::isDemoId($id) || (is_string($companyId) && DemoBusinesses::isDemo($companyId))) {
            Log::info('GoCardless call refused for a demo business', ['method' => $method, 'company_id' => $companyId]);

            throw new GoCardlessException(DemoBusinesses::goCardlessMessage());
        }
    }
}
