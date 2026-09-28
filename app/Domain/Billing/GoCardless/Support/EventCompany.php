<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\GoCardless\Models\GoCardlessEvent;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Tenancy\Models\Company;

/**
 * Which company a webhook event is about, from the ids we stored (payment, subscription, mandate, billing
 * request), then the `company_id` metadata we put on everything we create. Null = not ours: GoCardless sends
 * every event of the organisation to every endpoint (the legacy backoffice may share it).
 */
final class EventCompany
{
    /**
     * @param  array<string, string>  $metadata  The fetched resource's metadata, when the caller has it.
     */
    public static function find(GoCardlessEvent $event, array $metadata = []): ?Company
    {
        $companyId = null;

        if (($payment = $event->link('payment')) !== null) {
            $companyId = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment)->value('company_id');
        }

        $accounts = BillingAccount::withoutCompanyScope();

        $companyId ??= match (true) {
            $event->link('subscription') !== null => $accounts->clone()->where('gc_subscription_id', $event->link('subscription'))->value('company_id'),
            default => null,
        };
        $companyId ??= $event->link('mandate') !== null ? $accounts->clone()->where('gc_mandate_id', $event->link('mandate'))->value('company_id') : null;
        $companyId ??= $event->link('mandate_request_mandate') !== null ? $accounts->clone()->where('gc_mandate_id', $event->link('mandate_request_mandate'))->value('company_id') : null;
        $companyId ??= $event->link('billing_request') !== null ? $accounts->clone()->where('gc_billing_request_id', $event->link('billing_request'))->value('company_id') : null;

        $fromMetadata = $metadata['company_id'] ?? ($event->payload['resource_metadata']['company_id'] ?? null);
        $companyId ??= is_string($fromMetadata) ? $fromMetadata : null;

        return is_string($companyId) ? Company::withTrashed()->find($companyId) : null;
    }

    public static function forMandate(?string $mandateId): ?Company
    {
        $companyId = $mandateId === null ? null : BillingAccount::withoutCompanyScope()->where('gc_mandate_id', $mandateId)->value('company_id');

        return is_string($companyId) ? Company::withTrashed()->find($companyId) : null;
    }
}
