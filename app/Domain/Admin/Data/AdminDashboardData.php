<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Queries\Dashboard\Attention;
use App\Domain\Admin\Queries\Dashboard\Change;
use App\Domain\Admin\Queries\Dashboard\Kpis;
use App\Domain\Admin\Queries\Dashboard\RecentActivity;
use App\Domain\Admin\Queries\Dashboard\RecentTenants;
use App\Domain\Admin\Queries\Dashboard\SystemHealth;
use App\Domain\TillHealth\Queries\TillHealthSummary;

/**
 * Every admin dashboard figure, the same for every admin (this is what is cached). `forViewer()` then removes
 * what an admin may not see: money (`area: billing`) without billing access, lead items without leads access.
 *
 * @phpstan-import-type Delta from Change
 * @phpstan-import-type Kpi from Kpis
 * @phpstan-import-type Tile from Kpis
 * @phpstan-import-type Item from Attention
 * @phpstan-import-type TenantRow from RecentTenants
 * @phpstan-import-type Health from SystemHealth
 * @phpstan-import-type Summary from TillHealthSummary
 * @phpstan-import-type Activity from RecentActivity
 * @phpstan-import-type StatusCounts from RecentActivity
 *
 * @phpstan-type Stored array{generatedAt: string, kpis: array<string, Kpi>, overview: array<string, Tile>, attention: list<Item>, attentionTotals: array<string, int>, recentTenants: list<TenantRow>, health: list<Health>, tills: Summary, activity: list<Activity>, statuses: StatusCounts}
 */
final readonly class AdminDashboardData
{
    /**
     * @param  array<string, Kpi>  $kpis
     * @param  array<string, Tile>  $overview
     * @param  list<Item>  $attention  Up to 5 per area.
     * @param  array<string, int>  $attentionTotals  Area → total items.
     * @param  list<TenantRow>  $recentTenants
     * @param  list<Health>  $health
     * @param  Summary  $tills  Module 2.7: Till health counts.
     * @param  list<Activity>  $activity  Module 7.1: newest tenants, paid invoices and leads (5 of each).
     * @param  StatusCounts  $statuses  Module 7.1: tenants per status.
     */
    public function __construct(
        public string $generatedAt,
        public array $kpis,
        public array $overview,
        public array $attention,
        public array $attentionTotals,
        public array $recentTenants,
        public array $health,
        public array $tills,
        public array $activity,
        public array $statuses,
    ) {}

    /**
     * @return Stored
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  Stored  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }

    /**
     * The page prop for one admin.
     *
     * @return array<string, mixed>
     */
    public function forViewer(bool $billing, bool $leads): array
    {
        $areas = array_values(array_filter(['tenants', 'licences', $billing ? 'billing' : null, $leads ? 'leads' : null]));
        $lock = fn (array $figure) => $figure['area'] === 'billing' && ! $billing
            ? ['locked' => true, 'value' => null, 'delta' => null, 'series' => [], 'footer' => null]
            : ['locked' => false, ...array_diff_key($figure, ['area' => true])];

        return [
            'generatedAt' => $this->generatedAt,
            'access' => ['billing' => $billing, 'leads' => $leads],
            'kpis' => array_map($lock, $this->kpis),
            'overview' => array_map(fn (array $tile) => array_diff_key($lock($tile), ['series' => true, 'footer' => true]), $this->overview),
            'attention' => [
                'items' => array_map(fn (array $item) => array_diff_key($item, ['area' => true]), Attention::visible($this->attention, $areas)),
                'total' => array_sum(array_intersect_key($this->attentionTotals, array_flip($areas))),
            ],
            'recentTenants' => array_map(fn (array $row) => [...$row, 'mrr' => $billing ? $row['mrr'] : null], $this->recentTenants),
            'health' => $this->health,
            'tills' => $this->tills,
            'activity' => array_map(fn (array $item) => array_diff_key($item, ['area' => true]), RecentActivity::visible($this->activity, $areas)),
            'statuses' => $this->statuses,
        ];
    }
}
