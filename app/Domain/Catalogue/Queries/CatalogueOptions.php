<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Unit;
use App\Domain\TillData\Models\VatRate;

/**
 * The pick lists of the catalogue screens (current company): departments, categories, VAT rates, units and the till's
 * age rules, each small enough to send whole.
 */
final class CatalogueOptions
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'departments' => Department::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'is_active', 'default_vat_rate_id'])
                ->map(fn (Department $d) => ['value' => $d->id, 'label' => (string) $d->name, 'isActive' => $d->is_active, 'defaultVatRateId' => $d->default_vat_rate_id])->all(),
            'categories' => Category::query()->orderBy('position')->orderBy('name')
                ->get(['id', 'name', 'department_id', 'parent_category_id', 'is_active', 'default_vat_rate_id', 'age_rule_default'])
                ->map(fn (Category $c) => [
                    'value' => $c->id, 'label' => (string) $c->name, 'departmentId' => $c->department_id, 'parentId' => $c->parent_category_id,
                    'isActive' => $c->is_active, 'defaultVatRateId' => $c->default_vat_rate_id, 'ageRuleDefault' => $c->age_rule_default?->value,
                ])->all(),
            'vatRates' => VatRate::query()->orderByDesc('is_default')->orderByDesc('percentage')->get(['id', 'name', 'code', 'percentage', 'is_default', 'effective_to'])
                ->map(fn (VatRate $v) => [
                    'value' => $v->id, 'label' => trim("{$v->name} (".self::percent((string) $v->percentage).')'), 'code' => $v->code,
                    'percentage' => (string) $v->percentage, 'isDefault' => $v->is_default,
                ])->all(),
            'units' => Unit::query()->orderBy('position')->orderBy('name')->get(['id', 'code', 'name', 'is_active'])
                ->map(fn (Unit $u) => ['value' => $u->id, 'label' => (string) $u->name, 'code' => (string) $u->code, 'isActive' => $u->is_active])->all(),
            'ageRules' => array_map(fn (AgeRule $r) => ['value' => $r->value, 'label' => self::ageRule($r)], AgeRule::cases()),
        ];
    }

    public static function ageRule(?AgeRule $rule): string
    {
        return match ($rule) {
            null, AgeRule::None => 'No age check',
            AgeRule::Over16 => '16 or over',
            AgeRule::Over18 => '18 or over',
            AgeRule::TobaccoGenerational => 'Tobacco (born on or after 1 Jan 2009 refused)',
            AgeRule::Nicotine => 'Nicotine and vapes (18)',
            AgeRule::Lottery18 => 'Lottery (18)',
            AgeRule::Knives18 => 'Knives and blades (18)',
            AgeRule::Fireworks18 => 'Fireworks (18)',
            AgeRule::Solvents18 => 'Solvents and lighter gas (18)',
            AgeRule::EnergyDrink16 => 'Energy drinks (16)',
            AgeRule::Paracetamol16 => 'Paracetamol (16)',
        };
    }

    public static function percent(string $value): string
    {
        $trimmed = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

        return ($trimmed === '' ? '0' : $trimmed).'%';
    }
}
