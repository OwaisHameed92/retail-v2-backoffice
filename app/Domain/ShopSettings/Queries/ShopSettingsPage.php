<?php

namespace App\Domain\ShopSettings\Queries;

use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use Illuminate\Support\Collection;

/**
 * Props for the Till settings page (module 4.9). Runs inside the company scope. For every shop: the business's
 * values and which shops have their own. For one shop: that shop's own values and what it gets otherwise (every
 * shop's value, else the till's default when the contract states it). Values include what the tills pushed (§10.3:
 * a till sends every shared setting it holds), so the page shows what the tills really use.
 */
final class ShopSettingsPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, ?Branch $shop, ?string $restrictedBranchId): array
    {
        $shops = Branch::query()->active()->when($restrictedBranchId !== null, fn ($q) => $q->whereKey($restrictedBranchId))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $catalogue = SettingCatalogue::all();
        $rows = TillSetting::query()->whereIn('scope', [SettingScope::Company->value, SettingScope::Branch->value])
            ->whereIn('setting_key', array_keys($catalogue))->get(['scope', 'scope_id', 'setting_key', 'value', 'updated_at']);

        $business = $rows->filter(fn (TillSetting $row) => $row->scope === SettingScope::Company && $row->scope_id === $company->id)
            ->pluck('value', 'setting_key');
        $own = $rows->filter(fn (TillSetting $row) => $row->scope === SettingScope::Branch && $row->scope_id === $shop?->id)
            ->pluck('value', 'setting_key');

        return [
            'shop' => $shop === null ? null : ['id' => $shop->id, 'name' => $shop->name, 'code' => $shop->code],
            'shops' => $shops->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'canEveryShop' => $restrictedBranchId === null,
            'sections' => self::sections($shop !== null),
            'values' => (object) ($shop === null ? $business : $own)->all(),
            'inherited' => (object) ($shop === null ? [] : self::inherited($catalogue, $business)),
            'overrides' => (object) ($shop === null ? self::overrides($rows, $shops) : []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function sections(bool $forShop): array
    {
        $sections = [];

        foreach (SettingCatalogue::sections() as $id => $section) {
            $settings = [];
            foreach ($section['settings'] as $key => $definition) {
                if ($forShop && ($definition['everyShopOnly'] ?? false)) {
                    continue; // One value for the whole business (e.g. shop.trading_hours): set under every shop.
                }
                $settings[] = ['key' => $key, ...$definition];
            }
            if ($settings === []) {
                continue; // Every setting of the section is for the whole business.
            }

            $sections[] = ['id' => $id, 'title' => $section['title'], 'description' => $section['description'], 'settings' => $settings];
        }

        return $sections;
    }

    /**
     * What a shop gets when it has no value of its own.
     *
     * @param  array<string, array<string, mixed>>  $catalogue
     * @param  Collection<string, string>  $business
     * @return array<string, array{value: string|null, from: string}>
     */
    private static function inherited(array $catalogue, Collection $business): array
    {
        $inherited = [];

        foreach ($catalogue as $key => $definition) {
            $inherited[$key] = $business->has($key)
                ? ['value' => $business->get($key), 'from' => 'everyShop']
                : ['value' => isset($definition['default']) ? (string) $definition['default'] : null, 'from' => 'default'];
        }

        return $inherited;
    }

    /**
     * The shops that have their own value, per key.
     *
     * @param  Collection<int, TillSetting>  $rows
     * @param  Collection<int, Branch>  $shops
     * @return array<string, list<string>>
     */
    private static function overrides(Collection $rows, Collection $shops): array
    {
        $names = $shops->pluck('name', 'id');
        $overrides = [];

        foreach ($rows as $row) {
            if ($row->scope === SettingScope::Branch && $names->has($row->scope_id)) {
                $overrides[$row->setting_key][] = (string) $names->get($row->scope_id);
            }
        }

        return $overrides;
    }
}
