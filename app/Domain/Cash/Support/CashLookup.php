<?php

namespace App\Domain\Cash\Support;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\TillUser;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Shared bits of the Cash and Z screens (module 5.4): the shop and till pickers, id → name lookups in the current
 * company's scope, and value formatting (money as 2 dp strings, instants as ISO-8601 UTC).
 */
final class CashLookup
{
    /**
     * @return array{shops: list<array{value: string, label: string}>, tills: list<array{value: string, label: string}>}
     */
    public static function options(CashFilters $filters): array
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
                'label' => self::tillLabel($r).($filters->shop === null && $shops->count() > 1 ? ' · '.$names[$r->branch_id] : ''),
            ])->values()->all(),
        ];
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function shops(iterable $ids): array
    {
        return Branch::query()->withTrashed()->whereKey(self::unique($ids))->get(['id', 'name'])
            ->mapWithKeys(fn (Branch $b) => [(string) $b->id => (string) $b->name])->all();
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function tills(iterable $ids): array
    {
        return Register::query()->withTrashed()->whereKey(self::unique($ids))->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Register $r) => [(string) $r->id => self::tillLabel($r)])->all();
    }

    /**
     * Till staff names; an id the portal does not know is left out (the screen shows "Unknown").
     *
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function staff(iterable $ids): array
    {
        return TillUser::query()->withTrashed()->whereKey(self::unique($ids))->get(['id', 'name'])
            ->mapWithKeys(fn (TillUser $u) => [(string) $u->id => (string) $u->name !== '' ? (string) $u->name : 'Unnamed'])->all();
    }

    public static function tillLabel(Register $register): string
    {
        return (string) $register->name !== '' ? (string) $register->name : 'Till '.$register->code;
    }

    public static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Money::normalise($value);
    }

    public static function iso(mixed $at): ?string
    {
        if ($at instanceof DateTimeInterface) {
            return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return is_string($at) && $at !== '' ? str_replace(' ', 'T', substr($at, 0, 19)).'Z' : null;
    }

    /**
     * The name for an id from a lookup, or null when blank or unknown.
     *
     * @param  array<string, string>  $names
     */
    public static function name(array $names, ?string $id): ?string
    {
        return $id !== null && $id !== '' ? ($names[$id] ?? null) : null;
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return list<string>
     */
    private static function unique(iterable $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $out[$id] = true;
            }
        }

        return array_keys($out);
    }
}
