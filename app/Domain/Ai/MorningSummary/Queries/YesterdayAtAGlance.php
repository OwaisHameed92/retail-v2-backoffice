<?php

namespace App\Domain\Ai\MorningSummary\Queries;

use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Data\SummaryAudience;
use App\Domain\Ai\MorningSummary\Models\MorningSummary;
use App\Domain\Ai\MorningSummary\Support\SummaryView;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The dashboard's "Yesterday at a glance" card (module 6.3): the morning summary for the shop chosen in the switcher
 * (a one-shop user: theirs) or every shop, with live figures (late syncs show up) and the AI paragraph of the user's
 * 07:00 email when it covered the same shops. Null when there is no news. Business facts cached for CACHE_SECONDS.
 */
final class YesterdayAtAGlance
{
    public const CACHE_SECONDS = 600;

    public function __construct(private readonly MorningFacts $facts) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Company $company, int $userId, CompanyRole $role, ?string $branchId, CarbonImmutable $now): ?array
    {
        $day = TradingDay::today($now)->subDay()->format('Y-m-d');
        /** @var CompanyFacts $facts */
        $facts = Cache::remember('morning-facts|'.$company->id.'|'.$day, self::CACHE_SECONDS, fn () => $this->facts->for($company, $now));
        $audience = new SummaryAudience($branchId !== null ? [$branchId] : null, $role);
        $view = SummaryView::build($facts, $audience);

        if ($view === null || ! SummaryView::hasNews($view)) {
            return null;
        }

        $stored = MorningSummary::query()->where('user_id', $userId)->where('trading_day', $day)
            ->where('scope_key', $audience->scopeKey())->where('narrative_status', MorningSummary::WRITTEN)->first();

        return [...$view, 'narrative' => $stored?->narrative];
    }
}
