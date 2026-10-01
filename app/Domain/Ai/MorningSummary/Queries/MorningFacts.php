<?php

namespace App\Domain\Ai\MorningSummary\Queries;

use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Support\SummaryView;
use App\Domain\Notifications\Queries\CashDigest;
use App\Domain\Notifications\Queries\ComplianceDigest;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Models\RptProductDaily;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\Units;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Everything a business's morning summary can say about yesterday (module 6.3), per shop, computed once from data and
 * then cut per user by {@see SummaryView}. The model never computes a figure.
 *
 * - sales: `rpt_sales_daily` for yesterday, the same weekday last week (−7 days) and last year (−364 days, same
 *   weekday), and the {@see self::BASELINE_DAYS} days before yesterday (usual refunds, voids, discounts per day);
 * - products: `rpt_product_daily` yesterday against the same weekday last week (top movers);
 * - issues: fast sellers at their low-stock point ({@see FastSellers}), open till / sync problems, yesterday's cash
 *   variances ({@see CashDigest}) and compliance due ({@see ComplianceDigest}).
 */
final class MorningFacts
{
    public const BASELINE_DAYS = 28;

    public function __construct(private readonly CurrentCompany $tenancy, private readonly FastSellers $fastSellers) {}

    public function for(Company $company, CarbonImmutable $now): CompanyFacts
    {
        $day = TradingDay::today($now)->subDay();
        $shops = DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();

        if ($shops === []) {
            return CompanyFacts::empty($day->format('Y-m-d'));
        }

        return $this->tenancy->runAs($company, fn () => new CompanyFacts(
            day: $day->format('Y-m-d'),
            shops: $shops,
            yesterday: $this->salesByShop($day, $day),
            lastWeek: $this->salesByShop($day->subDays(7), $day->subDays(7)),
            lastYear: $this->salesByShop($day->subDays(364), $day->subDays(364)),
            baseline: $this->baseline($day),
            products: $this->products($day),
            fastSellers: $this->fastSellers->for($company->id, $shops, $day),
            tills: TillProblems::for($company->id, $shops),
            cash: CashDigest::for($shops, $day->format('Y-m-d')),
            compliance: ComplianceDigest::for($shops, $now),
        ));
    }

    /**
     * @return array<string, SalesTotals>
     */
    private function salesByShop(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $t = ReportTables::SALES_DAILY;
        $def = ReportTables::TABLES[$t];
        $out = [];

        $rows = ReportScope::tenant($from, $to)->query(RptSalesDaily::class)->groupBy("{$t}.branch_id")
            ->select(["{$t}.branch_id", ...Sums::select($t, $def['decimals'], $def['counts'])])->get();

        foreach ($rows as $row) {
            $out[(string) $row->branch_id] = SalesTotals::fromSums(Sums::read($row, $def['decimals'], $def['counts']));
        }

        return $out;
    }

    /**
     * Per shop over the baseline window: the trading days with sales and the summed refunds, voids and manual
     * discounts of those days.
     *
     * @return array<string, array{days: int, refunds: string, voids: string, discounts: string}>
     */
    private function baseline(CarbonImmutable $day): array
    {
        $t = ReportTables::SALES_DAILY;
        $def = ReportTables::TABLES[$t];
        $out = [];

        $rows = ReportScope::tenant($day->subDays(self::BASELINE_DAYS), $day->subDay())->query(RptSalesDaily::class)
            ->groupBy("{$t}.branch_id", "{$t}.trading_day")
            ->select(["{$t}.branch_id", ...Sums::select($t, $def['decimals'], $def['counts'])])->get();

        foreach ($rows as $row) {
            $totals = SalesTotals::fromSums(Sums::read($row, $def['decimals'], $def['counts']));

            if ($totals->transactions === 0) {
                continue;
            }

            $key = (string) $row->branch_id;
            $entry = $out[$key] ?? ['days' => 0, 'refunds' => '0.00', 'voids' => '0.00', 'discounts' => '0.00'];
            $out[$key] = [
                'days' => $entry['days'] + 1,
                'refunds' => bcadd($entry['refunds'], $totals->refundGross, 2),
                'voids' => bcadd($entry['voids'], $totals->voidTotal, 2),
                'discounts' => bcadd($entry['discounts'], $totals->manualDiscount(), 2),
            ];
        }

        return $out;
    }

    /**
     * Product sales (inc VAT, net of refunds) yesterday and the same weekday last week, per shop.
     *
     * @return array<string, array<string, array{name: string, now: string, before: string}>>
     */
    private function products(CarbonImmutable $day): array
    {
        $t = ReportTables::PRODUCT_DAILY;
        $sums = ['gross' => 2];
        $today = $day->format('Y-m-d');
        $out = [];

        $rows = ReportScope::tenant($day->subDays(7), $day)->query(RptProductDaily::class)
            ->whereIn("{$t}.trading_day", [$today, $day->subDays(7)->format('Y-m-d')])
            ->leftJoin('products as p', fn (JoinClause $j) => $j->on('p.id', '=', "{$t}.product_id")->on('p.company_id', '=', "{$t}.company_id"))
            ->groupBy("{$t}.branch_id", "{$t}.product_id", "{$t}.trading_day")
            ->select(["{$t}.branch_id", "{$t}.product_id", "{$t}.trading_day", DB::raw('MAX(p.name) as product_name'),
                DB::raw("MAX({$t}.last_name) as line_name"), ...Sums::select($t, $sums)])
            ->get();

        foreach ($rows as $row) {
            $branch = (string) $row->branch_id;
            $product = (string) $row->product_id;
            $name = trim((string) ($row->product_name ?? '')) !== '' ? trim((string) $row->product_name) : trim((string) ($row->line_name ?? ''));
            $entry = $out[$branch][$product] ?? ['name' => $name !== '' ? $name : 'Unknown product', 'now' => '0.00', 'before' => '0.00'];
            $gross = Units::decimal(Units::of(((array) $row)['sum_gross'] ?? null), 2);
            $entry[substr((string) $row->trading_day, 0, 10) === $today ? 'now' : 'before'] = $gross;
            $out[$branch][$product] = $entry;
        }

        return $out;
    }
}
