<?php

namespace App\Domain\StaffTime\Support;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Staff\Models\StaffBranch;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\ClockEvent;
use App\Domain\TillData\Models\TillUser;
use Carbon\CarbonImmutable;

/**
 * Pickers and people of the staff time screens (module 5.6), in the current company's scope. A one-shop user sees
 * their shop and only the staff assigned to it or who clocked in there.
 */
final class TimeLookup
{
    /**
     * @return array{shops: list<array{value: string, label: string}>, tills: list<array{value: string, label: string}>, people: list<array{value: string, label: string}>}
     */
    public static function options(TimeFilters $filters): array
    {
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);
        $names = $shops->pluck('name', 'id');
        $tills = Register::query()->whereIn('branch_id', $shops->pluck('id'))
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->orderBy('branch_id')->orderBy('code')->get(['id', 'branch_id', 'name', 'code']);

        return [
            'shops' => $shops->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => (string) $b->name])->values()->all(),
            'tills' => $tills->map(fn (Register $r) => [
                'value' => (string) $r->id,
                'label' => CashLookup::tillLabel($r).($filters->shop === null && $shops->count() > 1 ? ' · '.$names[$r->branch_id] : ''),
            ])->values()->all(),
            'people' => collect(self::people($filters))->map(fn (string $name, string $id) => ['value' => $id, 'label' => $name])->values()->all(),
        ];
    }

    /**
     * Staff the viewer may pick: id => name, by name. Every till user; for a shop, those assigned to it or who clocked
     * in there.
     *
     * @return array<string, string>
     */
    public static function people(TimeFilters $filters): array
    {
        $query = TillUser::query()->orderBy('name');

        if ($filters->shop !== null) {
            $ids = StaffBranch::query()->where('branch_id', $filters->shop)->pluck('till_user_id')
                ->merge(ClockEvent::query()->where('branch_id', $filters->shop)
                    ->where('at', '>=', CarbonImmutable::parse($filters->from)->subDays(7)->toDateString())->distinct()->pluck('user_id'))
                ->filter()->unique()->values();
            $query->whereKey($ids->all());
        }

        return $query->get(['id', 'name'])
            ->mapWithKeys(fn (TillUser $u) => [(string) $u->id => (string) $u->name !== '' ? (string) $u->name : 'Unnamed'])->all();
    }

    /**
     * Hourly rate and max shift hours of the given staff (rate null when the till has none).
     *
     * @param  iterable<string>  $ids
     * @return array<string, array{rate: ?string, maxShiftMinutes: ?int}>
     */
    public static function pay(iterable $ids): array
    {
        $ids = collect($ids)->unique()->values()->all();

        return TillUser::query()->withTrashed()->whereKey($ids)->get(['id', 'rate_per_hour', 'max_shift_hours'])
            ->mapWithKeys(fn (TillUser $u) => [(string) $u->id => [
                'rate' => (float) $u->rate_per_hour > 0 ? CashLookup::money($u->rate_per_hour) : null,
                'maxShiftMinutes' => (float) $u->max_shift_hours > 0 ? (int) round((float) $u->max_shift_hours * 60) : null,
            ]])->all();
    }

    /**
     * @template T
     *
     * @param  list<T>  $rows
     * @return array{data: list<T>, meta: array<string, mixed>}
     */
    public static function page(array $rows, int $page, int $perPage): array
    {
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min($page, $lastPage);

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage, 'search' => null, 'sort' => null, 'direction' => 'desc'],
        ];
    }
}
