<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The four KPI cards and the two business overview tiles. Money cards carry `area: billing` so AdminDashboard
 * can hide them from admins without billing access. Series are 12 London weeks, oldest first, zeros where
 * nothing happened.
 *
 * @phpstan-import-type Delta from Change
 *
 * @phpstan-type Kpi array{area: string|null, value: string, delta: Delta|null, series: list<float|int>, footer: string|null}
 * @phpstan-type Tile array{area: string|null, value: string, delta: Delta|null}
 */
final class Kpis
{
    public const TRIAL_ENDING_DAYS = 7;

    public function __construct(private readonly DashboardRows $rows) {}

    /**
     * @return array<string, Kpi>
     */
    public function cards(): array
    {
        return [
            'revenue' => $this->revenue(),
            'activeTills' => $this->activeTills(),
            'trials' => $this->trials(),
            'overdue' => $this->overdue(),
        ];
    }

    /**
     * @return array<string, Tile>
     */
    public function overview(): array
    {
        $now = $this->rows->now;
        $monthStart = Buckets::monthStart($now);
        $tenants = fn (CarbonImmutable $at) => $this->rows->companies->filter(fn (Company $company) => History::companyLive($company, $at))->count();
        $current = Buckets::weeks($now, 12);
        $previous = Buckets::weeks($now, 12, 12);
        $revenue = DashboardRows::paidBetween($this->rows->paid, $current[0]['start'], $now->addSecond());
        $before = DashboardRows::paidBetween($this->rows->paid, $previous[0]['start'], $current[0]['start']);
        $count = $tenants($now);

        return [
            'tenants' => ['area' => null, 'value' => number_format($count), 'delta' => Change::count($count, $tenants($monthStart), 'since last month')],
            'revenue' => ['area' => 'billing', 'value' => BillingFormat::money($revenue), 'delta' => Change::percent($revenue, $before, 'vs previous 12 weeks')],
        ];
    }

    /**
     * When each company's trial ends now: the earliest end of its tills still on trial.
     *
     * @return array<string, CarbonImmutable>
     */
    public function trialEnds(): array
    {
        $now = $this->rows->now;
        $ends = [];

        foreach ($this->rows->licences as $licence) {
            if ($licence->trial_ends_at === null || ! History::trial($licence, $now, $now) || ! History::companyLive($this->rows->company($licence->company_id), $now)) {
                continue;
            }

            $current = $ends[$licence->company_id] ?? null;
            $ends[$licence->company_id] = $current === null ? $licence->trial_ends_at : $current->min($licence->trial_ends_at);
        }

        return $ends;
    }

    /**
     * @return Kpi
     */
    private function revenue(): array
    {
        $now = $this->rows->now;
        $monthStart = Buckets::monthStart($now);
        $lastMonthStart = $monthStart->setTimezone(Buckets::TIMEZONE)->subMonthNoOverflow()->utc();
        $current = DashboardRows::paidBetween($this->rows->paid, $monthStart, $now->addSecond());
        $last = DashboardRows::paidBetween($this->rows->paid, $lastMonthStart, $monthStart);
        $series = array_map(
            fn (array $week) => (float) DashboardRows::paidBetween($this->rows->paid, $week['start'], $week['end']),
            Buckets::weeks($now),
        );

        return [
            'area' => 'billing',
            'value' => BillingFormat::money($current),
            'delta' => Change::percent($current, $last, 'vs last month'),
            'series' => $series,
            'footer' => BillingFormat::money($last).' last month',
        ];
    }

    /**
     * @return Kpi
     */
    private function activeTills(): array
    {
        $series = $this->weekly(fn (CarbonImmutable $at) => $this->rows->licences
            ->filter(fn (Licence $licence) => History::activeTill($licence, $at, $this->rows->now)
                && History::companyLive($this->rows->company($licence->company_id), $at))
            ->count());
        $now = $series[11];
        $lastWeek = $series[10];

        return [
            'area' => null,
            'value' => number_format($now),
            'delta' => Change::count($now, $lastWeek, 'vs last week'),
            'series' => $series,
            'footer' => number_format($lastWeek).' at the end of last week',
        ];
    }

    /**
     * @return Kpi
     */
    private function trials(): array
    {
        $series = $this->weekly(fn (CarbonImmutable $at) => $this->rows->licences
            ->filter(fn (Licence $licence) => History::trial($licence, $at, $this->rows->now)
                && History::companyLive($this->rows->company($licence->company_id), $at))
            ->pluck('company_id')->unique()->count());
        $soon = $this->rows->now->addDays(self::TRIAL_ENDING_DAYS);
        $ending = collect($this->trialEnds())->filter(fn (CarbonImmutable $end) => $end->lessThanOrEqualTo($soon))->count();

        return [
            'area' => null,
            'value' => number_format($series[11]),
            'delta' => Change::count($series[11], $series[10], 'vs last week'),
            'series' => $series,
            'footer' => $ending === 0 ? 'None end in the next 7 days' : $ending.' end in the next 7 days',
        ];
    }

    /**
     * @return Kpi
     */
    private function overdue(): array
    {
        $now = $this->rows->now;
        $owed = fn (CarbonImmutable $at) => $this->rows->overdue->filter(fn (Invoice $invoice) => History::overdue($invoice, $at, $now));
        $amounts = array_map(
            fn (array $week) => Money::sum($owed(Buckets::at($week, $now))->map(fn (Invoice $invoice) => History::overdueAmount($invoice))),
            Buckets::weeks($now),
        );
        $count = $owed($now)->count();

        return [
            'area' => 'billing',
            'value' => BillingFormat::money($amounts[11]),
            'delta' => Change::percent($amounts[11], $amounts[10], 'vs last week', 'down'),
            'series' => array_map(fn (string $amount) => (float) $amount, $amounts),
            'footer' => $count === 0 ? 'No overdue invoices' : $count.' '.($count === 1 ? 'invoice' : 'invoices').' overdue',
        ];
    }

    /**
     * A stock measured at the end of each of the last 12 weeks (now for the current week).
     *
     * @param  callable(CarbonImmutable): int  $measure
     * @return list<int>
     */
    private function weekly(callable $measure): array
    {
        return array_map(fn (array $week) => $measure(Buckets::at($week, $this->rows->now)), Buckets::weeks($this->rows->now));
    }
}
