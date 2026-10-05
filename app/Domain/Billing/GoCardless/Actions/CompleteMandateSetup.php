<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Tenancy\Models\Company;

/**
 * The customer finished the hosted setup: reads the billing request and, once it is fulfilled, applies its
 * mandate (ApplyMandate finalises: setup fee, subscription). Called from the `billing_requests.fulfilled` webhook
 * (the source of truth) and from the page GoCardless sends the customer back to; whichever comes first wins.
 */
class CompleteMandateSetup
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly ApplyMandate $applyMandate,
    ) {}

    /**
     * @return 'finalised'|'updated'|'lost'|'ignored'|'pending'
     *
     * @throws GoCardlessException
     */
    public function handle(Company $company, string $billingRequestId): string
    {
        DemoBusinesses::refuseGoCardless($company);

        $request = $this->client->billingRequest($billingRequestId);

        if ($request->mandateId === null) {
            return 'pending';
        }

        return $this->applyMandate->handle($company, $this->client->mandate($request->mandateId));
    }
}
