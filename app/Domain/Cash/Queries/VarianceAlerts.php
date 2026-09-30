<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Cash\Support\ZTotals;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\CashOfficeBankingStatus;
use App\Domain\TillData\Enums\ShiftStatus;
use App\Domain\TillData\Models\CashOfficeBanking;
use App\Domain\TillData\Models\CashOfficeReconciliation;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Shift;
use App\Domain\TillData\Models\ShiftTender;
use App\Domain\TillData\Models\ZReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Variance alerts (module 5.4): differences at or above the alert amount in the chosen days, newest first.
 *
 * - a closed shift whose largest payment type difference (`ShiftTender.variance`) reaches the amount, or that the
 *   till flagged itself (`ZReport.totalsJson` `HasVarianceWarning`, DASHBOARD.md §2.5);
 * - a safe / cash office count (`CashOfficeReconciliation.variance`) and a banking the bank confirmed differently
 *   (`CashOfficeBanking.varianceAmount`, not cancelled).
 *
 * The amount is the shop's own till setting `cash.variance_alert_over` (shop, else business), else DEFAULT; the
 * `threshold` filter overrides it for every shop. Negative = short, positive = over.
 */
final class VarianceAlerts
{
    public const SETTING = 'cash.variance_alert_over';

    public const DEFAULT = '5.00';

    public const PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public static function for(CashFilters $filters, int $page, int $perPage = self::PER_PAGE): array
    {
        [$default, $byShop] = self::thresholds();
        $limit = fn (?string $branch) => $filters->threshold ?? ($branch !== null ? ($byShop[$branch] ?? $default) : $default);
        $rows = [...self::shifts($filters, $limit), ...self::counts($filters, $limit), ...self::bankings($filters, $limit)];
        usort($rows, fn (array $a, array $b) => [(string) $b['at'], $b['id']] <=> [(string) $a['at'], $a['id']]);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $shops = CashLookup::shops(array_column($slice, 'branchId'));
        $tills = CashLookup::tills(array_column($slice, 'registerId'));

        return [
            'alerts' => [
                'data' => array_map(function (array $r) use ($shops, $tills) {
                    $r['shop'] = CashLookup::name($shops, $r['branchId']);
                    $r['till'] = CashLookup::name($tills, $r['registerId']);
                    unset($r['branchId'], $r['registerId']);

                    return $r;
                }, $slice),
                'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage, 'search' => null, 'sort' => null, 'direction' => 'desc'],
            ],
            'summary' => [
                'total' => $total,
                'short' => count(array_filter($rows, fn (array $r) => Money::isNegative($r['variance']))),
                'shifts' => count(array_filter($rows, fn (array $r) => $r['kind'] === 'shift')),
                'office' => count(array_filter($rows, fn (array $r) => $r['kind'] !== 'shift')),
                'threshold' => $filters->threshold,
                'defaultThreshold' => $default,
                'shopThresholds' => $byShop !== [],
            ],
        ];
    }

    /**
     * The business's alert amount and each shop's own, from the till settings.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function thresholds(): array
    {
        $default = self::DEFAULT;
        $byShop = [];
        $settings = DB::table('till_settings')->where('company_id', app(CurrentCompany::class)->require()->id)
            ->where('setting_key', self::SETTING)->whereNull('deleted_at')->whereIn('scope', ['company', 'branch'])->get(['scope', 'scope_id', 'value']);

        foreach ($settings as $s) {
            $value = trim((string) $s->value, " \"'");

            if (! is_numeric($value) || (float) $value < 0) {
                continue;
            }

            if ($s->scope === 'company') {
                $default = Money::normalise($value);
            } elseif (is_string($s->scope_id) && $s->scope_id !== '') {
                $byShop[$s->scope_id] = Money::normalise($value);
            }
        }

        return [$default, $byShop];
    }

    /**
     * @param  callable(?string): string  $limit
     * @return list<array<string, mixed>>
     */
    private static function shifts(CashFilters $filters, callable $limit): array
    {
        $shifts = $filters->during($filters->scope(Shift::query()), 'closed_at')->where('status', ShiftStatus::Closed->value)->get(['id', 'branch_id', 'register_id', 'closed_at', 'variance_total']);

        if ($shifts->isEmpty()) {
            return [];
        }

        $ids = $shifts->pluck('id')->all();
        $lines = ShiftTender::query()->whereIn('shift_id', $ids)->get(['shift_id', 'payment_type_id', 'variance'])->groupBy('shift_id');
        $names = PaymentType::query()->withTrashed()->pluck('name', 'id');
        $zs = ZReport::query()->whereIn('shift_id', $ids)->get(['id', 'shift_id', 'totals_json'])->keyBy('shift_id');
        $out = [];

        foreach ($shifts as $shift) {
            $worst = $lines->get($shift->id, collect())->sortByDesc(fn (ShiftTender $t) => abs((float) $t->variance))->first();
            $z = $zs->get($shift->id);
            $flagged = $z !== null && ZTotals::parse($z->totals_json)->warning;
            $amount = $limit($shift->branch_id);
            $variance = $worst !== null ? Money::normalise($worst->variance) : Money::normalise($shift->variance_total);

            if (! $flagged && Money::compare(ltrim($variance, '-'), $amount) < 0) {
                continue;
            }

            $out[] = [
                'id' => $shift->id, 'kind' => 'shift', 'at' => CashLookup::iso($shift->closed_at), 'day' => $shift->closed_at !== null ? TradingDay::of($shift->closed_at)[0] : null,
                'branchId' => $shift->branch_id, 'registerId' => $shift->register_id,
                'what' => $worst !== null ? (string) ($names[$worst->payment_type_id] ?? 'Unknown payment type') : 'All payment types',
                'variance' => $variance, 'shiftVariance' => Money::normalise($shift->variance_total), 'threshold' => $amount, 'tillFlag' => $flagged, 'shiftId' => $shift->id, 'zId' => $z?->id,
            ];
        }

        return $out;
    }

    /**
     * @param  callable(?string): string  $limit
     * @return list<array<string, mixed>>
     */
    private static function counts(CashFilters $filters, callable $limit): array
    {
        $out = [];

        foreach ($filters->onDays($filters->scope(CashOfficeReconciliation::query(), false))->where('variance', '!=', 0)->get() as $c) {
            $amount = $limit($c->branch_id);

            if (Money::compare(ltrim(Money::normalise($c->variance), '-'), $amount) >= 0) {
                $out[] = [
                    'id' => $c->id, 'kind' => 'safeCount', 'at' => CashLookup::iso($c->counted_at), 'day' => $c->trading_date->format('Y-m-d'),
                    'branchId' => $c->branch_id, 'registerId' => null, 'what' => 'Safe / cash office count',
                    'variance' => Money::normalise($c->variance), 'shiftVariance' => null, 'threshold' => $amount, 'tillFlag' => false, 'shiftId' => null, 'zId' => null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  callable(?string): string  $limit
     * @return list<array<string, mixed>>
     */
    private static function bankings(CashFilters $filters, callable $limit): array
    {
        $out = [];
        $query = $filters->during($filters->scope(CashOfficeBanking::query(), false), 'prepared_at')->whereNotNull('variance_amount')->where('variance_amount', '!=', 0)
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', '!=', CashOfficeBankingStatus::Cancelled->value));

        foreach ($query->get() as $b) {
            $amount = $limit($b->branch_id);
            $variance = Money::normalise($b->variance_amount);

            if (Money::compare(ltrim($variance, '-'), $amount) >= 0) {
                $at = $b->banked_at ?? $b->collected_at ?? $b->prepared_at;
                $out[] = [
                    'id' => $b->id, 'kind' => 'banking', 'at' => CashLookup::iso($at), 'day' => TradingDay::of($at)[0],
                    'branchId' => $b->branch_id, 'registerId' => null, 'what' => 'Banking'.($b->reference !== '' ? ' '.$b->reference : ''),
                    'variance' => $variance, 'shiftVariance' => null, 'threshold' => $amount, 'tillFlag' => false, 'shiftId' => null, 'zId' => null,
                ];
            }
        }

        return $out;
    }
}
