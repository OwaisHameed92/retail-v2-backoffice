<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiClient;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Tenancy\Enums\CompanyStatus;

/**
 * Checks before every model call, in this order: kill switch, provider key, company status, plan feature,
 * monthly budget. Throws AiUnavailable with a message that is safe to show.
 */
final class AiGate
{
    public function __construct(
        private readonly AiClient $client,
        private readonly AiBudget $budget,
    ) {}

    public function isAvailable(AiContext $context): bool
    {
        try {
            $this->ensureAvailable($context);

            return true;
        } catch (AiUnavailable) {
            return false;
        }
    }

    /**
     * @throws AiUnavailable
     */
    public function ensureAvailable(AiContext $context): void
    {
        if (! AiSettings::enabled()) {
            throw AiUnavailable::disabled();
        }

        if (! $this->client->isConfigured()) {
            throw AiUnavailable::notConfigured();
        }

        $company = $context->company;

        if ($company !== null && ! $context->isAdmin()) {
            if ($company->trashed() || in_array($company->status, [CompanyStatus::Suspended, CompanyStatus::Cancelled], true)) {
                throw AiUnavailable::companyInactive();
            }

            $feature = $context->feature->planFeature();

            if ($feature !== null && AiPlan::for($company)?->hasFeature($feature) !== true) {
                throw AiUnavailable::notInPlan($context->feature);
            }
        }

        if ($this->budget->remaining($context) <= 0) {
            throw AiUnavailable::budgetExhausted($this->budget->resetsOn());
        }
    }
}
