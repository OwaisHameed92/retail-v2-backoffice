<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Enums\AisleSource;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\DisplayName;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;

/**
 * The store's aisles (OS-11): how customers browse it (header, home tiles and rows, footer, aisle pages, sitemap).
 *
 * - Built from the till's departments or categories (`aisle_source`; automatic: departments when there are at least
 *   {@see self::GOOD} real ones, else whichever gives more aisles).
 * - Never the till's "no department" bucket or any name that means none ({@see self::isNone()}). Products with no
 *   aisle still show in search and "All products".
 * - Only aisles with at least one product that can be ordered now at the shop (shown and in stock), counted.
 * - Names in Title Case (DisplayName) unless the shop gave its own; the shop's order first, then the till's; aisles
 *   the shop hid are left out (`aisles` settings: id, name, photo, hidden).
 * - OS-12: the one choice of departments for the store: they drive the header, the "Shop by aisle" tiles and the
 *   department rows. A store with no saved aisles uses its old home-row departments ({@see self::settings()}).
 *
 * Read only; scoped to the store's business.
 */
final class StoreAisles
{
    /** Fewer real aisles than this: no big home tiles, and automatic tries categories. */
    public const GOOD = 3;

    /** Till names that mean "no department / no category". */
    private const NONE = '/^(?:no|none|not?|without|un)?[\s_-]*(?:dept|department|departments|category|categories|assigned|categori[sz]ed|set|known|n\/?a|null|default|-+|\.+)?$/i';

    /**
     * The aisles shown at the shop, in order.
     *
     * @return list<array{id: string, kind: string, name: string, tillName: string, count: int, photoUrl: string|null, customName: string|null, hidden: bool}>
     */
    public function handle(OnlineStore $store, OnlineStoreShop $shop): array
    {
        return array_values(array_filter($this->all($store, $shop), fn (array $aisle) => ! $aisle['hidden'] && $aisle['count'] > 0));
    }

    /**
     * Every candidate aisle with the shop's settings, hidden and empty ones included (the builder's list).
     *
     * @return list<array{id: string, kind: string, name: string, tillName: string, count: int, photoUrl: string|null, customName: string|null, hidden: bool}>
     */
    public function all(OnlineStore $store, OnlineStoreShop $shop): array
    {
        $source = $this->source($store, $shop);
        $candidates = $this->candidates($store, $shop, $source);
        [$settings, $hideOthers] = self::settings($store, $source, array_column($candidates, 'id'));
        $aisles = [];

        foreach ($candidates as $i => $c) {
            $own = $settings[$c['id']] ?? null;
            $aisles[] = [
                'id' => $c['id'], 'kind' => $source->value, 'tillName' => $c['name'],
                'name' => $own['name'] ?? DisplayName::of($c['name']), 'customName' => $own['name'] ?? null,
                'count' => $c['count'], 'photoUrl' => StoreImages::url($own['photo'] ?? null), 'hidden' => $own['hidden'] ?? $hideOthers,
                'order' => [$own === null ? 1 : 0, $own['position'] ?? $i],
            ];
        }

        usort($aisles, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(function (array $a): array {
            unset($a['order']);

            return $a;
        }, $aisles);
    }

    /**
     * The shop's aisle settings by id (position, name, photo, hidden), and whether aisles without one are hidden.
     *
     * OS-12 (read-time fallback): a store that never saved its aisles but picked departments for its home rows
     * (`home_departments`, the builder's old second picker) gets those departments as its aisles, in that order, and
     * the others hidden, while its aisles are built from departments and at least one of them is an aisle. Saving the
     * aisles replaces this; nothing is rewritten.
     *
     * @param  list<string>  $candidateIds
     * @return array{0: array<string, array{position: int, name: string|null, photo: string|null, hidden: bool}>, 1: bool}
     */
    private static function settings(OnlineStore $store, AisleSource $source, array $candidateIds): array
    {
        $settings = [];

        foreach (is_array($store->aisles) ? $store->aisles : [] as $i => $row) {
            if (is_string($row['id'] ?? null)) {
                $settings[$row['id']] = ['position' => $i, 'name' => self::text($row['name'] ?? null), 'photo' => self::text($row['photo'] ?? null),
                    'hidden' => (bool) ($row['hidden'] ?? false)];
            }
        }

        if ($settings !== [] || $source !== AisleSource::Departments) {
            return [$settings, false];
        }

        $picked = array_values(array_intersect(FeaturedProducts::pickedIds($store->home_departments), $candidateIds));

        if ($picked === []) {
            return [[], false];
        }

        foreach ($picked as $i => $id) {
            $settings[$id] = ['position' => $i, 'name' => null, 'photo' => null, 'hidden' => false];
        }

        return [$settings, true];
    }

    /** Departments or categories for this store (its choice, else the better of the two). */
    public function source(OnlineStore $store, OnlineStoreShop $shop): AisleSource
    {
        $chosen = AisleSource::tryFrom((string) $store->aisle_source);

        if ($chosen !== null) {
            return $chosen;
        }

        $departments = count($this->candidates($store, $shop, AisleSource::Departments));

        if ($departments >= self::GOOD) {
            return AisleSource::Departments;
        }

        return count($this->candidates($store, $shop, AisleSource::Categories)) > $departments ? AisleSource::Categories : AisleSource::Departments;
    }

    /** Whether a till department or category name means "none" (never an aisle). */
    public static function isNone(string $name): bool
    {
        return preg_match(self::NONE, trim($name)) === 1;
    }

    /** @var array<string, list<array{id: string, name: string, count: int}>> */
    private array $candidates = [];

    /**
     * Real departments or categories with orderable products at the shop, in the till's order, with counts.
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    private function candidates(OnlineStore $store, OnlineStoreShop $shop, AisleSource $source): array
    {
        return $this->candidates[$shop->id.'|'.$source->value] ??= StoreScope::run($store, function () use ($store, $shop, $source): array {
            $column = $source === AisleSource::Departments ? 'products.department_id' : 'products.category_id';
            $query = OnlineCatalogueQuery::base($shop)->whereNotNull($column);
            OnlineCatalogueQuery::filter($query, $shop, new CatalogueFilters(inStockOnly: true));
            $counts = $query->groupBy($column)->selectRaw("{$column} as aisle_id, COUNT(*) as aggregate")->toBase()->pluck('aggregate', 'aisle_id');

            if ($counts->isEmpty()) {
                return [];
            }

            $model = $source === AisleSource::Departments ? Department::query() : Category::query();

            return $model->where('company_id', $store->company_id)->whereIn('id', $counts->keys()->map(fn ($id) => (string) $id)->all())
                ->orderBy('position')->orderBy('name')->get(['id', 'name'])
                ->filter(fn ($row) => trim((string) $row->name) !== '' && ! self::isNone((string) $row->name))
                ->map(fn ($row) => ['id' => (string) $row->id, 'name' => trim((string) $row->name), 'count' => (int) $counts[$row->id]])
                ->values()->all();
        });
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
