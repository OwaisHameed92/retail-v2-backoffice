<?php

namespace App\Domain\Ai\Queries;

use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Support\AiBudget;
use App\Domain\Ai\Support\AiPlan;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A business's AI use this month (module 6.2): tokens against the monthly allowance (AiBudget, the same numbers the
 * gate enforces), model calls, and the split by feature and by person. Shown on /app/billing and in the assistant
 * panel. The pounds cost is ours (Anthropic's price), not shown to the business.
 */
final class AiUsageSummary
{
    /**
     * @return array{used: int, limit: int, remaining: int, percent: int, resetsOn: string, calls: int, included: bool, byFeature: list<array{feature: string, label: string, tokens: int, calls: int}>, byPerson: list<array{name: string, tokens: int, calls: int}>}
     */
    public static function for(Company $company): array
    {
        $budget = app(AiBudget::class);
        $plan = AiPlan::for($company);
        $limit = $budget->companyLimit($plan);
        $base = AiUsage::query()->where('company_id', $company->getKey())->whereNull('admin_id')->where('created_at', '>=', $budget->monthStart());

        $features = (clone $base)->toBase()->groupBy('feature')
            ->select(['feature', DB::raw('SUM(total_tokens) as tokens'), DB::raw('COUNT(*) as calls')])->get();
        $people = (clone $base)->toBase()->whereNotNull('user_id')->groupBy('user_id')
            ->select(['user_id', DB::raw('SUM(total_tokens) as tokens'), DB::raw('COUNT(*) as calls')])
            ->orderByDesc('tokens')->limit(10)->get();
        $names = User::query()->whereKey($people->pluck('user_id'))->pluck('name', 'id');

        $used = (int) $features->sum('tokens');
        $feature = AiFeature::Assistant->planFeature();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'percent' => $limit > 0 ? (int) min(100, floor($used * 100 / $limit)) : 100,
            'resetsOn' => $budget->resetsOn(),
            'calls' => (int) $features->sum('calls'),
            'included' => $feature !== null && $plan?->hasFeature($feature) === true,
            'byFeature' => $features->map(function (object $row) {
                $feature = AiFeature::tryFrom((string) $row->feature);

                return ['feature' => (string) $row->feature, 'label' => $feature?->label() ?? (string) $row->feature, 'tokens' => (int) $row->tokens, 'calls' => (int) $row->calls];
            })->sortByDesc('tokens')->values()->all(),
            'byPerson' => $people->map(fn (object $row) => [
                'name' => (string) ($names[$row->user_id] ?? 'Former user'),
                'tokens' => (int) $row->tokens,
                'calls' => (int) $row->calls,
            ])->values()->all(),
        ];
    }
}
