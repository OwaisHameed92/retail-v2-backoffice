<?php

namespace App\Domain\Parcels\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\Parcel;
use App\Domain\TillData\Models\ParcelCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Parcel activity (module 5.10), read only: Parcel and ParcelCarrier are branch-owned (ownership.json), so each shop's
 * till registers drop-offs and collections and keeps its own carrier list. Counts for the chosen days (by
 * registration time), parcels waiting now (open, any date) with those waiting over a week, per-carrier counts, the
 * shops' carriers and the parcels themselves (searchable by tracking code or customer). A one-shop user sees their
 * shop only.
 */
final class ParcelActivity
{
    public const DIRECTIONS = ['dropOff', 'collection'];

    public const STATUSES = ['open', 'handedOver'];

    public const WAITING_DAYS = 7;

    /**
     * @param  array{carrier: string|null, direction: string|null, status: string|null}  $only
     * @return array<string, mixed>
     */
    public static function for(Request $request, CashFilters $filters, array $only, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $inRange = fn () => $filters->during($filters->scope(Parcel::query(), false), 'registered_at_utc');
        $waiting = fn () => $filters->scope(Parcel::query(), false)->where('status', 'open');
        $carriers = $filters->scope(ParcelCarrier::query(), false)->get();
        $carrierNames = $carriers->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        $shops = CashLookup::shops($carriers->pluck('branch_id')->merge($inRange()->distinct()->pluck('branch_id')));
        $carriers = $carriers->sortBy(fn (ParcelCarrier $c) => [(int) $c->position, mb_strtolower((string) $c->name), $shops[$c->branch_id] ?? ''])->values();

        $query = $inRange()
            ->when($only['carrier'] !== null, fn ($q) => $q->where('carrier_id', $only['carrier']))
            ->when($only['direction'] !== null, fn ($q) => $q->where('direction', $only['direction']))
            ->when($only['status'] !== null, fn ($q) => $q->where('status', $only['status']))
            ->orderByDesc('registered_at_utc');
        $rows = TableQuery::from($request)->searchable(['tracking_code', 'customer_name'])->defaultPerPage(25)
            ->paginate($query, fn (Parcel $p) => [
                'id' => (string) $p->id,
                'trackingCode' => (string) $p->tracking_code,
                'customer' => (string) $p->customer_name !== '' ? (string) $p->customer_name : null,
                'carrier' => CashLookup::name($carrierNames, $p->carrier_id),
                'shop' => CashLookup::name($shops, $p->branch_id),
                'direction' => $p->direction?->value,
                'status' => $p->status?->value,
                'registeredAt' => CashLookup::iso($p->registered_at_utc),
                'handedOverAt' => CashLookup::iso($p->handed_over_at_utc),
                'idCheck' => (string) $p->hand_over_id_check_note !== '' ? (string) $p->hand_over_id_check_note : null,
                'waitingDays' => $p->status?->value === 'open' ? (int) floor($p->registered_at_utc->diffInDays($now)) : null,
            ]);

        $byCarrier = $inRange()->toBase()->selectRaw("carrier_id,
                sum(case when direction = 'dropOff' then 1 else 0 end) as drop_offs,
                sum(case when direction = 'collection' then 1 else 0 end) as collections,
                sum(case when status = 'handedOver' then 1 else 0 end) as handed_over, count(*) as total")
            ->groupBy('carrier_id')->get()->keyBy('carrier_id');
        $waitingByCarrier = $waiting()->toBase()->selectRaw('carrier_id, count(*) as n')->groupBy('carrier_id')->pluck('n', 'carrier_id');
        $total = fn (string $column) => (int) $byCarrier->sum($column);

        return [
            'parcels' => $rows,
            'summary' => [
                'registered' => $total('total'),
                'dropOffs' => $total('drop_offs'),
                'collections' => $total('collections'),
                'handedOver' => $total('handed_over'),
                'waiting' => (int) $waiting()->count(),
                'waitingLong' => (int) $waiting()->where('registered_at_utc', '<', $now->subDays(self::WAITING_DAYS)->format('Y-m-d H:i:s'))->count(),
                'waitingDays' => self::WAITING_DAYS,
            ],
            'carriers' => $carriers->map(fn (ParcelCarrier $c) => [
                'id' => (string) $c->id,
                'name' => (string) $c->name,
                'shop' => CashLookup::name($shops, $c->branch_id),
                'isActive' => (bool) $c->is_active,
                'dropOffs' => (int) ($byCarrier->get($c->id)->drop_offs ?? 0),
                'collections' => (int) ($byCarrier->get($c->id)->collections ?? 0),
                'handedOver' => (int) ($byCarrier->get($c->id)->handed_over ?? 0),
                'waiting' => (int) ($waitingByCarrier[$c->id] ?? 0),
            ])->values()->all(),
            'carrierOptions' => $carriers->map(fn (ParcelCarrier $c) => [
                'value' => (string) $c->id,
                'label' => (string) $c->name.($filters->shop === null && count(array_unique($carriers->pluck('branch_id')->all())) > 1 ? ' · '.(CashLookup::name($shops, $c->branch_id) ?? '') : ''),
            ])->values()->all(),
            'only' => $only,
        ];
    }

    /**
     * @return array{carrier: string|null, direction: string|null, status: string|null}
     */
    public static function only(Request $request): array
    {
        $carrier = $request->query('carrier');
        $direction = $request->query('type');
        $status = $request->query('status');

        return [
            'carrier' => is_string($carrier) && preg_match('/^[0-9A-Za-z-]{1,64}$/', $carrier) === 1 ? $carrier : null,
            'direction' => in_array($direction, self::DIRECTIONS, true) ? (string) $direction : null,
            'status' => in_array($status, self::STATUSES, true) ? (string) $status : null,
        ];
    }
}
