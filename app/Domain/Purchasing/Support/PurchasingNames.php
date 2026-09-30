<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\Supplier;

/**
 * Names for the ids purchasing rows carry (shop, supplier, product), looked up once per page in the current company's
 * scope. The till's own `supplierName` snapshot wins when the supplier is unknown here.
 */
final class PurchasingNames
{
    /** @var array<string, array{name: string, code: string}> */
    public array $shops = [];

    /** @var array<string, string> */
    public array $suppliers = [];

    /** @var array<string, array{name: string, sku: string|null}> */
    public array $products = [];

    /**
     * @param  iterable<object>  $rows  rows with branch_id, supplier_id and/or product_id
     */
    public static function for(iterable $rows): self
    {
        $ids = ['branch_id' => [], 'supplier_id' => [], 'product_id' => []];

        foreach ($rows as $row) {
            foreach (array_keys($ids) as $column) {
                $value = $row->{$column} ?? null;

                if (is_string($value) && $value !== '') {
                    $ids[$column][] = $value;
                }
            }
        }

        $names = new self;
        $names->shops = Branch::query()->withTrashed()->whereKey(array_values(array_unique($ids['branch_id'])))->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Branch $b) => [$b->id => ['name' => $b->name, 'code' => $b->code]])->all();
        $names->suppliers = Supplier::query()->withTrashed()->whereKey(array_values(array_unique($ids['supplier_id'])))
            ->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
        $names->products = Product::query()->withTrashed()->whereKey(array_values(array_unique($ids['product_id'])))->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn (Product $p) => [$p->id => ['name' => (string) $p->name, 'sku' => $p->sku ?: null]])->all();

        return $names;
    }

    public function shop(?string $id): ?string
    {
        return $id === null ? null : ($this->shops[$id]['name'] ?? null);
    }

    public function supplier(?string $id, ?string $snapshot = null): string
    {
        return ($id !== null ? ($this->suppliers[$id] ?? null) : null) ?: ($snapshot ?: 'Unknown supplier');
    }

    /** @return array{name: string, sku: string|null} */
    public function product(?string $id, ?string $fallback = null): array
    {
        return ($id !== null ? ($this->products[$id] ?? null) : null) ?? ['name' => $fallback ?: 'Unknown product', 'sku' => null];
    }
}
