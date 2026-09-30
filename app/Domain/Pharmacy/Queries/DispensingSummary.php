<?php

namespace App\Domain\Pharmacy\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\DispensingItem;
use App\Domain\TillData\Models\DispensingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The dispensing screen (module 5.10), read only: DispensingRecord is branch-owned (the pharmacy's till writes it).
 * Counts by charge status (paid NHS charge, exempt, private), exemptions, shops and periods (days up to 31 days,
 * else weeks from Monday), charges taken, and the records themselves. Patients are never shown: the till keeps
 * who a prescription was for; the portal only needs the counts and charges. A one-shop user sees their shop only.
 */
final class DispensingSummary
{
    public const CHARGES = ['paid', 'exempt', 'private'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, CashFilters $filters, ?string $charge, ?string $exemption): array
    {
        $base = fn () => $filters->during($filters->scope(DispensingRecord::query(), false), 'dispensed_at');
        $totals = $base()->toBase()->selectRaw("count(*) as records,
            sum(case when charge_status = 'paid' then 1 else 0 end) as paid,
            sum(case when charge_status = 'exempt' then 1 else 0 end) as exempt,
            sum(case when charge_status = 'private' then 1 else 0 end) as private,
            sum(case when charge_status = 'paid' then charge_amount else 0 end) as nhs_charges,
            sum(case when charge_status = 'private' then charge_amount else 0 end) as private_charges")->first();
        $items = DispensingItem::query()->whereIn('dispensing_record_id', $base()->select('id'))->count();

        $shops = CashLookup::shops($base()->distinct()->pluck('branch_id'));
        $rows = self::filtered($base(), $charge, $exemption)->withCount('dispensingItems')->orderByDesc('dispensed_at');
        $page = TableQuery::from($request)->defaultPerPage(25)->paginate($rows, fn (DispensingRecord $r) => self::row($r, $shops));

        return [
            'records' => $page,
            'summary' => [
                'records' => (int) ($totals->records ?? 0),
                'items' => $items,
                'paid' => (int) ($totals->paid ?? 0),
                'exempt' => (int) ($totals->exempt ?? 0),
                'private' => (int) ($totals->private ?? 0),
                'nhsCharges' => Money::normalise($totals->nhs_charges ?? '0'),
                'privateCharges' => Money::normalise($totals->private_charges ?? '0'),
            ],
            'exemptions' => $base()->where('charge_status', 'exempt')->toBase()->selectRaw('exemption, count(*) as n')
                ->groupBy('exemption')->orderByDesc('n')->get()->map(fn ($r) => ['exemption' => (string) $r->exemption, 'count' => (int) $r->n])->values()->all(),
            'shops' => $base()->toBase()->selectRaw("branch_id, count(*) as records,
                    sum(case when charge_status = 'paid' then 1 else 0 end) as paid,
                    sum(case when charge_status = 'exempt' then 1 else 0 end) as exempt,
                    sum(case when charge_status = 'private' then 1 else 0 end) as private,
                    sum(case when charge_status in ('paid', 'private') then charge_amount else 0 end) as charges")
                ->groupBy('branch_id')->get()
                ->map(fn ($r) => [
                    'shop' => CashLookup::name($shops, $r->branch_id) ?? 'Unknown shop', 'records' => (int) $r->records, 'paid' => (int) $r->paid,
                    'exempt' => (int) $r->exempt, 'private' => (int) $r->private, 'charges' => Money::normalise($r->charges ?? '0'),
                ])->sortBy('shop')->values()->all(),
            'periods' => self::periods($base(), $filters),
            'charge' => $charge,
            'exemption' => $exemption,
        ];
    }

    /**
     * @param  Builder<DispensingRecord>  $query
     * @return Builder<DispensingRecord>
     */
    private static function filtered(Builder $query, ?string $charge, ?string $exemption): Builder
    {
        return $query->when($charge !== null, fn ($q) => $q->where('charge_status', $charge))
            ->when($exemption !== null, fn ($q) => $q->where('exemption', $exemption));
    }

    /**
     * Counts per London day (ranges up to 31 days) or per week from Monday.
     *
     * @param  Builder<DispensingRecord>  $query
     * @return array{unit: 'day'|'week', rows: list<array{period: string, records: int, paid: int, exempt: int, private: int}>}
     */
    private static function periods(Builder $query, CashFilters $filters): array
    {
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $unit = $from->diffInDays($to) < 31 ? 'day' : 'week';
        $key = fn (CarbonImmutable $day) => ($unit === 'day' ? $day : $day->startOfWeek(CarbonImmutable::MONDAY))->format('Y-m-d');
        $rows = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $rows[$key($day)] ??= ['period' => $key($day), 'records' => 0, 'paid' => 0, 'exempt' => 0, 'private' => 0];
        }

        foreach ($query->toBase()->select(['dispensed_at', 'charge_status'])->cursor() as $r) {
            $period = $key(TradingDay::today(CarbonImmutable::parse((string) $r->dispensed_at, 'UTC')));

            if (isset($rows[$period])) {
                $rows[$period]['records']++;
                $status = (string) $r->charge_status;
                if (in_array($status, self::CHARGES, true)) {
                    $rows[$period][$status]++;
                }
            }
        }

        return ['unit' => $unit, 'rows' => array_values($rows)];
    }

    /**
     * @param  array<string, string>  $shops
     * @return array<string, mixed>
     */
    private static function row(DispensingRecord $r, array $shops): array
    {
        return [
            'id' => (string) $r->id,
            'dispensedAt' => CashLookup::iso($r->dispensed_at),
            'shop' => CashLookup::name($shops, $r->branch_id),
            'prescriber' => (string) $r->prescriber_name !== '' ? (string) $r->prescriber_name : null,
            'prescriberRegistration' => (string) $r->prescriber_registration !== '' ? (string) $r->prescriber_registration : null,
            'prescriptionDate' => substr((string) $r->getRawOriginal('prescription_date'), 0, 10) ?: null, // the till may leave it empty
            'dispensedBy' => (string) $r->dispensed_by_name !== '' ? (string) $r->dispensed_by_name : null,
            'chargeStatus' => $r->charge_status?->value,
            'exemption' => $r->exemption?->value,
            'chargeAmount' => CashLookup::money($r->charge_amount) ?? '0.00',
            'items' => (int) $r->getAttribute('dispensing_items_count'),
            'hasSale' => (string) $r->sale_id !== '',
        ];
    }
}
