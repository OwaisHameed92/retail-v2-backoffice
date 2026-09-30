<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The products list (module 4.2) of the current company, built for 100k products per business:
 *
 * - every filter is an indexed column: `(company_id, name)` for the default sort, `(company_id, department_id)`,
 *   `(company_id, category_id)`, `(company_id, sku)`; a search is an exact barcode (`(company_id, barcode)`), a code
 *   prefix or part of the name;
 * - one page of rows, then one query for that page's primary barcodes; department, category and VAT names come from
 *   small lookups, never a join per row.
 */
final class ProductList
{
    public const SORTABLE = ['name', 'sell_price', 'cost_price', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $table = TableQuery::from($request)->sortable(self::SORTABLE)->defaultSort('name');
        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? (string) $request->query('status') : 'active';
        $department = self::id($request, 'department');
        $category = self::id($request, 'category');
        $search = $table->search();

        $query = Product::query()
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'archived', fn (Builder $q) => $q->where('is_active', false))
            ->when($department !== null, fn (Builder $q) => $q->where('department_id', $department))
            ->when($category !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('category_id', $category)->orWhere('sub_category_id', $category)))
            ->when($search !== null, fn (Builder $q) => self::search($q, (string) $search));

        $departments = Department::query()->pluck('name', 'id')->all();
        $categories = Category::query()->pluck('name', 'id')->all();
        $vat = VatRate::query()->get(['id', 'code', 'percentage'])->keyBy('id');
        $page = $table->paginate($query);
        $ids = array_map(fn (Product $p) => $p->id, $page['data']);
        $barcodes = [];

        foreach (ProductBarcode::query()->whereIn('product_id', $ids)->orderByDesc('is_primary')->orderBy('created_at')->get(['product_id', 'barcode']) as $row) {
            $barcodes[$row->product_id][] = $row->barcode;
        }

        $page['data'] = array_map(fn (Product $p) => [
            'id' => $p->id,
            'name' => (string) $p->name,
            'sku' => $p->sku,
            'brand' => $p->brand,
            'department' => $departments[$p->department_id] ?? null,
            'category' => $categories[$p->sub_category_id ?? ''] ?? $categories[$p->category_id] ?? null,
            'sellPrice' => (string) $p->sell_price,
            'costPrice' => (string) $p->cost_price,
            'vat' => ($rate = $vat->get($p->vat_rate_id)) ? CatalogueOptions::percent((string) $rate->percentage) : null,
            'barcode' => $barcodes[$p->id][0] ?? null,
            'barcodeCount' => count($barcodes[$p->id] ?? []),
            'ageRestricted' => $p->age_rule !== null && $p->age_rule->value !== 'none',
            'isActive' => $p->is_active,
            'updatedAt' => $p->updated_at?->toIso8601ZuluString(),
        ], $page['data']);

        return [
            'products' => $page,
            'filters' => ['status' => $status, 'department' => $department, 'category' => $category],
            'counts' => [
                'active' => Product::query()->where('is_active', true)->count(),
                'archived' => Product::query()->where('is_active', false)->count(),
                'departments' => count($departments),
                'categories' => count($categories),
            ],
        ];
    }

    /**
     * Exact barcode, code prefix or part of the name.
     *
     * @param  Builder<Product>  $query
     */
    private static function search(Builder $query, string $term): void
    {
        $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('sku', 'like', $term.'%')
                ->orWhereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', $term));
        });
    }

    private static function id(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1 ? $value : null;
    }
}
