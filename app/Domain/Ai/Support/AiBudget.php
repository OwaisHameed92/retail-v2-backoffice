<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Monthly token allowance. Months are calendar months in Europe/London; usage rows are UTC.
 *
 * Company limit: `ai.budgets.plans.<plan code>` if set, else `ai.budgets.default_monthly_tokens`.
 * Admin calls share one pool: `ai.budgets.admin_monthly_tokens`.
 */
final class AiBudget
{
    public const TIMEZONE = 'Europe/London';

    public function limitFor(AiContext $context): int
    {
        if ($context->company === null || $context->isAdmin()) {
            return max(0, (int) config('ai.budgets.admin_monthly_tokens', 0));
        }

        return $this->companyLimit(AiPlan::for($context->company));
    }

    public function companyLimit(?Plan $plan): int
    {
        /** @var array<string, int> $plans */
        $plans = (array) config('ai.budgets.plans', []);

        if ($plan !== null && array_key_exists($plan->code, $plans)) {
            return max(0, (int) $plans[$plan->code]);
        }

        return max(0, (int) config('ai.budgets.default_monthly_tokens', 0));
    }

    public function usedBy(AiContext $context): int
    {
        $query = AiUsage::query()->where('created_at', '>=', $this->monthStart());

        if ($context->company === null || $context->isAdmin()) {
            $query->whereNotNull('admin_id');
        } else {
            $query->where('company_id', $context->company->getKey())->whereNull('admin_id');
        }

        return (int) $query->sum('total_tokens');
    }

    public function usedByCompany(Company $company): int
    {
        return $this->usedBy(AiContext::forSystem($company, AiFeature::Assistant));
    }

    public function remaining(AiContext $context): int
    {
        return max(0, $this->limitFor($context) - $this->usedBy($context));
    }

    /** Start of the current Europe/London month, in UTC. */
    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfMonth()->utc();
    }

    /** "1 October 2026": when the allowance resets. */
    public function resetsOn(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfMonth()->addMonth()->format('j F Y');
    }
}
