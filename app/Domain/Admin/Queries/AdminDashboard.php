<?php

namespace App\Domain\Admin\Queries;

use App\Domain\Admin\Data\AdminDashboardData;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Enums\DashboardRange;
use App\Domain\Admin\Models\Admin;
use App\Domain\Admin\Queries\Dashboard\Attention;
use App\Domain\Admin\Queries\Dashboard\DashboardRows;
use App\Domain\Admin\Queries\Dashboard\Kpis;
use App\Domain\Admin\Queries\Dashboard\RecentTenants;
use App\Domain\Admin\Queries\Dashboard\RevenueChart;
use App\Domain\Admin\Queries\Dashboard\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The super admin dashboard (module 1.9), across every tenant:
 *
 *     $dashboard->forAdmin($admin);                                 // the "dashboard" page prop
 *     $dashboard->revenueFor($admin, DashboardRange::SixMonths);    // the "revenue" chart prop (null without billing)
 *
 * Figures are worked out once for everybody and cached for 60 seconds; what one admin may see is removed after
 * the cache (money needs billing.manage, lead items leads.manage), so the cache never carries one role's view
 * to another. A bounded number of queries (about 15) whatever the number of tenants.
 */
final class AdminDashboard
{
    public const CACHE_SECONDS = 60;

    public const CACHE_KEY = 'admin-dashboard:v1';

    public function data(): AdminDashboardData
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->compute(CarbonImmutable::now())->toArray());

        return AdminDashboardData::fromArray($stored);
    }

    public function compute(CarbonImmutable $now): AdminDashboardData
    {
        $rows = DashboardRows::load($now);
        $kpis = new Kpis($rows);
        $attention = Attention::collect($rows, $kpis->trialEnds());

        return new AdminDashboardData(
            generatedAt: $now->toIso8601String(),
            kpis: $kpis->cards(),
            overview: $kpis->overview(),
            attention: $attention['items'],
            attentionTotals: $attention['totals'],
            recentTenants: RecentTenants::collect($rows),
            health: SystemHealth::check($now),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Admin $admin): array
    {
        return $this->data()->forViewer(self::seesBilling($admin), self::seesLeads($admin));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function revenueFor(Admin $admin, DashboardRange $range): ?array
    {
        if (! self::seesBilling($admin)) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY.':revenue:'.$range->value, self::CACHE_SECONDS, fn () => RevenueChart::for($range, CarbonImmutable::now()));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);

        foreach (DashboardRange::cases() as $range) {
            Cache::forget(self::CACHE_KEY.':revenue:'.$range->value);
        }
    }

    public static function seesBilling(Admin $admin): bool
    {
        return $admin->hasAbility(AdminRole::BILLING_MANAGE);
    }

    public static function seesLeads(Admin $admin): bool
    {
        return $admin->hasAbility(AdminRole::LEADS_MANAGE);
    }
}
