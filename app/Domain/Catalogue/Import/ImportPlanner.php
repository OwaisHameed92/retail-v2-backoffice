<?php

namespace App\Domain\Catalogue\Import;

use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;

/**
 * Decides what each row of a batch does (preview and apply alike): finds the product by barcode, else by product code
 * (two queries per batch, both on indexes), and checks what a row needs against the business's data. Adds messages to
 * `ImportRow::$errors`; returns the product each row updates (null = a new product).
 */
final class ImportPlanner
{
    public function __construct(private readonly ImportLookups $lookups) {}

    /**
     * @param  list<ImportRow>  $rows
     * @return array<int, Product|null> line → product to update (null = create)
     */
    public function plan(array $rows): array
    {
        $barcodes = array_values(array_filter(array_map(fn (ImportRow $r) => $r->barcode, $rows)));
        $skus = array_values(array_filter(array_map(fn (ImportRow $r) => $r->sku, $rows)));
        $byBarcode = $barcodes === [] ? [] : ProductBarcode::query()->whereIn('barcode', $barcodes)->pluck('product_id', 'barcode')->all();
        $bySku = [];

        foreach ($skus === [] ? [] : Product::query()->whereIn('sku', $skus)->get(['id', 'sku']) as $p) {
            $bySku[(string) $p->sku][] = $p->id;
        }

        $ids = array_unique([...array_values($byBarcode), ...array_merge([], ...array_values($bySku))]);
        $products = $ids === [] ? collect() : Product::query()->whereIn('id', $ids)->get()->keyBy('id');
        $plan = [];

        foreach ($rows as $row) {
            $viaBarcode = $row->barcode !== null ? ($byBarcode[$row->barcode] ?? null) : null;
            $viaSku = $row->sku !== null ? ($bySku[$row->sku] ?? []) : [];

            if ($viaBarcode === null && count($viaSku) > 1) {
                $row->errors[] = "More than one product has the code {$row->sku}. Add a barcode to say which one.";
            } elseif ($viaBarcode !== null && count($viaSku) === 1 && $viaSku[0] !== $viaBarcode) {
                $row->errors[] = "Barcode {$row->barcode} and code {$row->sku} are on two different products.";
            }

            $product = $products->get($viaBarcode ?? ($viaSku[0] ?? ''));
            $this->check($row, $product);
            $plan[$row->line] = $product;
        }

        return $plan;
    }

    private function check(ImportRow $row, ?Product $product): void
    {
        if ($row->vat !== null && $this->lookups->vatId($row->vat) === null) {
            $row->errors[] = Country::tax('VAT rate')." \"{$row->vat}\" ".Country::tax('is not one of your VAT rates.');
        }

        if ($product !== null) {
            return; // an update may carry one column; a new category goes into the product's own department
        }

        foreach (['name' => 'a name', 'sell_price' => 'a sell price'] as $field => $what) {
            if (! array_key_exists($field, $row->attributes)) {
                $row->errors[] = "A new product needs {$what}.";
            }
        }

        if ($row->department === null || $row->category === null) {
            $row->errors[] = 'A new product needs a department and a category.';
        }

        $departmentId = $this->lookups->departmentId($row->department);
        $categoryId = $this->lookups->categoryId($departmentId, $row->category);

        if ($row->vat === null && $this->lookups->defaultVat($departmentId, $categoryId) === null) {
            $row->errors[] = Country::tax('A new product needs a VAT rate (no default VAT rate is set).');
        }
    }
}
