<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\Catalogue\Queries\CatalogueOptions;
use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * "Add from catalogue" (/app/products/catalogue): one page of the master catalogue for the current business, searched
 * by name, brand or barcode and filtered by department, each row marked when the business already sells that barcode
 * (one query for the page). Plus the business's departments for the mapping step and its sharing setting.
 */
final class CatalogueSearch
{
    public const SORTABLE = ['name', 'rrp', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, CurrentCompany $tenancy): array
    {
        $table = TableQuery::from($request)->sortable(self::SORTABLE)->defaultSort('name');
        $department = is_string($request->query('department')) && $request->query('department') !== '' ? mb_substr((string) $request->query('department'), 0, 120) : null;
        $search = $table->search();

        $query = MasterProduct::query()->current()
            ->when($department !== null, fn (Builder $q) => $q->where('department', $department))
            ->when($search !== null, fn (Builder $q) => self::search($q, (string) $search));

        $page = $table->paginate($query);
        $owned = self::owned(array_map(fn (MasterProduct $p) => $p->barcode, $page['data']));
        $page['data'] = array_map(fn (MasterProduct $p) => [...MasterRow::of($p), 'inCatalogue' => isset($owned[$p->barcode])], $page['data']);
        $options = CatalogueOptions::all();

        return [
            'products' => $page,
            'filters' => ['department' => $department],
            'departments' => MasterRow::departments(),
            'yourDepartments' => $options['departments'],
            'hasVatRates' => $options['vatRates'] !== [],
            'priceRule' => ['mode' => (new PriceRule)->mode, 'margin' => (new PriceRule)->margin],
            'sharing' => $tenancy->require()->share_unknown_barcodes !== false,
        ];
    }

    /**
     * The barcodes (or their UPC/EAN twins) this business already has.
     *
     * @param  list<string>  $codes
     * @return array<string, true>
     */
    public static function owned(array $codes): array
    {
        $variants = [];

        foreach ($codes as $code) {
            foreach (Gtin::variants($code) as $variant) {
                $variants[$variant] = $code;
            }
        }

        if ($variants === []) {
            return [];
        }

        $owned = [];

        foreach (ProductBarcode::query()->whereIn('barcode', array_map('strval', array_keys($variants)))->pluck('barcode') as $barcode) {
            $owned[(string) $variants[$barcode]] = true;
        }

        return $owned;
    }

    /**
     * Exact barcode (any UPC/EAN form), or every word in the name or brand.
     *
     * @param  Builder<MasterProduct>  $query
     */
    public static function search(Builder $query, string $term): void
    {
        $code = Gtin::normalise($term);

        $digits = str_replace(' ', '', $term);

        if ($code !== null) {
            $query->whereIn('barcode', Gtin::variants($code));

            return;
        }

        if (ctype_digit($digits)) {
            $query->where('barcode', 'like', $digits.'%');

            return;
        }

        foreach (array_slice(preg_split('/\s+/', trim($term)) ?: [], 0, 6) as $word) {
            $like = '%'.$word.'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('brand', 'like', $like));
        }
    }
}
