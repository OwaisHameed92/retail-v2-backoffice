<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Shared\Country\TillProfile;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Models\TillUser;

/**
 * Shared bits of the Compliance screens (module 5.7): the shop and staff pickers, age rule words, and the id → name
 * lookups and value formatting of the Cash screens (CashLookup), all in the current company's scope.
 */
final class ComplianceLookup
{
    /**
     * @return array{shops: list<array{value: string, label: string}>, staff: list<array{value: string, label: string}>}
     */
    public static function options(ComplianceFilters $filters): array
    {
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);

        return [
            'shops' => $shops->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => (string) $b->name])->values()->all(),
            'staff' => TillUser::query()->orderBy('name')->limit(500)->get(['id', 'name'])
                ->map(fn (TillUser $u) => ['value' => (string) $u->id, 'label' => (string) $u->name !== '' ? (string) $u->name : 'Unnamed'])->values()->all(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function ageRules(): array
    {
        return array_values(array_map(
            fn (AgeRule $r) => ['value' => $r->value, 'label' => self::ageRule($r->value)],
            array_filter(AgeRule::cases(), fn (AgeRule $r) => $r !== AgeRule::None && TillProfile::offersAgeRule($r->value)),
        ));
    }

    public static function ageRule(?string $value): string
    {
        return match ($value) {
            'over16' => '16+',
            'over18' => '18+',
            'tobaccoGenerational' => 'Tobacco (born 2009 or later)',
            'nicotine' => 'Nicotine and vapes 18+',
            'lottery18' => 'Lottery 18+',
            'knives18' => 'Knives 18+',
            'fireworks18' => 'Fireworks 18+',
            'solvents18' => 'Solvents 18+',
            'energyDrink16' => 'Energy drinks 16+',
            'paracetamol16' => 'Paracetamol 16+',
            'none', null, '' => 'No age rule',
            default => $value,
        };
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function shops(iterable $ids): array
    {
        return CashLookup::shops($ids);
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function staff(iterable $ids): array
    {
        return CashLookup::staff($ids);
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<string, string>
     */
    public static function tills(iterable $ids): array
    {
        return CashLookup::tills($ids);
    }

    /** @param  array<string, string>  $names */
    public static function name(array $names, ?string $id): ?string
    {
        return CashLookup::name($names, $id);
    }

    public static function iso(mixed $at): ?string
    {
        return CashLookup::iso($at);
    }

    public static function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /** A percentage to 1 dp of part / total, or null when the total is 0. */
    public static function rate(int $part, int $total): ?string
    {
        return $total > 0 ? number_format($part * 100 / $total, 1, '.', '') : null;
    }
}
