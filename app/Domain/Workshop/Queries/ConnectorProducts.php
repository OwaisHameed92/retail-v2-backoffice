<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\TillData\Models\Product;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\ProductScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/Products` and `GET /api/Product/{id}` (WORKSHOP-BIKEBOOK.md §3.3), read inside the connection's company.
 *
 * - No `lastUpdated`: every product in scope (ProductScope), `enabled: true`.
 * - With `lastUpdated`: top-level products whose row, a variant, a barcode, or this shop's BranchProduct / BranchPrice
 *   changed at or after it (`updatedAt`, or last received from a till: `synced_at`); in scope → `enabled: true`, otherwise (archived,
 *   `never`, out of the categories) `enabled: false`, so BikeBook never silently loses a product a job uses. When the
 *   scope settings themselves changed since then, every active product is sent again with its `enabled` state.
 * - Paged by id; `fields=id,updated` answers the light list.
 * - `Product/{id}`: any active product of the business, even outside the scope (BikeBook's "Verify" of a labour
 *   product); a variant's id answers its parent.
 */
final class ConnectorProducts
{
    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(WorkshopConnection $connection, ?CarbonImmutable $since, int $page, int $size, bool $light): array
    {
        $scope = ProductScope::for($connection);
        $query = DB::table('products')->where('company_id', $connection->company_id)->whereNull('parent_product_id');

        if ($since === null) {
            $scope->apply($query);
        } elseif (! $scope->changedSince($since)) {
            $query->whereIn('id', $this->changedTopLevel($connection, $since));
        } else {
            $query->where(fn (Builder $q) => ProductScope::active($q)->orWhereIn('id', $this->changedTopLevel($connection, $since)));
        }

        $total = (clone $query)->count();
        $ids = $query->orderBy('id')->forPage($page, $size)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $inScope = $ids === [] ? [] : array_flip($scope->apply(DB::table('products')->where('company_id', $connection->company_id)->whereIn('id', $ids))->pluck('id')->map(fn ($id) => (string) $id)->all());

        return [$this->models($connection, $ids, $inScope, $light), $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function one(WorkshopConnection $connection, string $id): ?array
    {
        $product = Product::query()->find($id);

        if ($product !== null && $product->parent_product_id !== null) {
            $product = Product::query()->find($product->parent_product_id);
        }

        if ($product === null || ! $product->is_active || $product->archived_at !== null) {
            return null;
        }

        $inScope = ProductScope::for($connection)->apply(DB::table('products')->where('company_id', $connection->company_id)->where('id', $product->id))->exists();

        return $this->models($connection, [$product->id], $inScope ? [$product->id => 0] : [], false)[0] ?? null;
    }

    /**
     * Top-level ids (a variant's parent) of products touched since the instant.
     */
    private function changedTopLevel(WorkshopConnection $connection, CarbonImmutable $since): Builder
    {
        $at = $since->utc()->format('Y-m-d H:i:s');
        $company = $connection->company_id;
        $branch = $connection->branch_id;
        $since = fn (Builder $q) => $q->where('updated_at', '>=', $at)->orWhere('synced_at', '>=', $at);
        $touched = DB::table('products')->select('id')->where('company_id', $company)->where($since)
            ->union(DB::table('product_barcodes')->select('product_id')->where('company_id', $company)->where($since))
            ->union(DB::table('branch_products')->select('product_id')->where('company_id', $company)->where('branch_id', $branch)->where($since))
            ->union(DB::table('branch_prices')->select('product_id')->where('company_id', $company)->where('branch_id', $branch)->where($since));

        return DB::table('products')->selectRaw('COALESCE(parent_product_id, id)')->where('company_id', $company)->whereIn('id', $touched);
    }

    /**
     * @param  list<string>  $ids  top-level product ids
     * @param  array<string, int>  $inScope
     * @return list<array<string, mixed>>
     */
    private function models(WorkshopConnection $connection, array $ids, array $inScope, bool $light): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');
        $children = Product::query()->whereIn('parent_product_id', $ids)->where('is_active', true)->whereNull('archived_at')
            ->orderBy('tile_position')->orderBy('id')->get()->groupBy('parent_product_id');
        $variantIds = [...$ids, ...$children->flatten()->pluck('id')->all()];
        $updated = $this->lastChanges($connection, $variantIds);
        $model = ProductModel::load($variantIds, $products->values()->all());
        $models = [];

        foreach ($ids as $id) {
            $product = $products->get($id);

            if (! $product instanceof Product) {
                continue;
            }

            $variants = $product->is_variant_parent ? $children->get($id, collect())->values()->all() : [$product];
            $stamp = collect([$product, ...$variants])->flatMap(fn (Product $p) => [$p->updated_at, $updated[$p->id] ?? null])->filter()->max();
            $enabled = isset($inScope[$id]);

            $models[] = $light
                ? ['id' => $product->id, 'updated' => ApiDate::format($stamp), 'enabled' => $enabled]
                : $model->product($product, $variants, $stamp, $enabled);
        }

        return $models;
    }

    /**
     * Latest change per product of its barcodes and this shop's stock and price rows.
     *
     * @param  list<string>  $ids
     * @return array<string, CarbonImmutable>
     */
    private function lastChanges(WorkshopConnection $connection, array $ids): array
    {
        $latest = [];
        $sources = [
            DB::table('product_barcodes')->where('company_id', $connection->company_id)->whereNull('deleted_at'),
            DB::table('branch_products')->where('company_id', $connection->company_id)->where('branch_id', $connection->branch_id),
            DB::table('branch_prices')->where('company_id', $connection->company_id)->where('branch_id', $connection->branch_id),
        ];

        foreach ($sources as $source) {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach ((clone $source)->whereIn('product_id', $chunk)->groupBy('product_id')->selectRaw('product_id, MAX(updated_at) as at, MAX(synced_at) as synced')->get() as $row) {
                    foreach ([$row->at, $row->synced] as $value) {
                        if ($value === null) {
                            continue;
                        }

                        $at = CarbonImmutable::parse((string) $value, 'UTC');
                        $current = $latest[(string) $row->product_id] ?? null;
                        $latest[(string) $row->product_id] = $current === null || $at->greaterThan($current) ? $at : $current;
                    }
                }
            }
        }

        return $latest;
    }
}
