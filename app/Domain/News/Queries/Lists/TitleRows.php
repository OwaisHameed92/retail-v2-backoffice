<?php

namespace App\Domain\News\Queries\Lists;

use App\Domain\News\Queries\NewsTitleForm;
use App\Domain\News\Support\NewsAccess;
use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\NewsTitle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * News titles (module 5.8): the portal's own rows (hub-owned). A shop filter shows that shop's titles and the ones
 * for every shop, as its till sees them. Each row carries the last four weeks' delivered and sold copies (from the
 * shops' delivery lines) and the VAT of the linked product.
 *
 * @extends DocumentRows<NewsTitle>
 */
final class TitleRows extends DocumentRows
{
    protected function base(): Builder
    {
        return NewsTitle::query();
    }

    public function statuses(): array
    {
        return ['active', 'archived'];
    }

    protected function sortable(): array
    {
        return ['name', 'publisher', 'cover_price'];
    }

    protected function defaultSort(): array
    {
        return ['name', 'asc'];
    }

    public function scoped(?string $shop): Builder
    {
        return $this->base()->when($shop !== null, fn (Builder $q) => $q->where(
            fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', '')->orWhere('branch_id', $shop),
        ));
    }

    protected function status(Builder $query, string $status): void
    {
        $query->where('is_active', $status === 'active');
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('name', 'like', $like)->orWhere('publisher', 'like', $like)->orWhere('linked_barcode', 'like', $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $ids = array_map(fn (NewsTitle $t) => $t->id, $rows);
        $company = app(CurrentCompany::class)->id();
        $since = CarbonImmutable::now('Europe/London')->subDays(27)->format('Y-m-d');
        $sold = DB::table('news_delivery_lines as l')->join('news_deliveries as d', 'd.id', '=', 'l.delivery_id')
            ->where('l.company_id', $company)->whereIn('l.title_id', $ids)->where('d.delivery_date', '>=', $since)->whereNull('l.deleted_at')->whereNull('d.deleted_at')
            ->groupBy('l.title_id')->selectRaw('l.title_id, sum(coalesce(l.qty_in, 0)) as qty_in, sum(coalesce(l.qty_sold, 0)) as qty_sold')
            ->get()->keyBy('title_id');
        $products = DB::table('products as p')->leftJoin('vat_rates as v', 'v.id', '=', 'p.vat_rate_id')
            ->where('p.company_id', $company)->whereIn('p.id', array_filter(array_map(fn (NewsTitle $t) => $t->linked_product_id, $rows)))
            ->get(['p.id', 'p.name', 'v.name as vat_name', 'v.percentage'])->keyBy('id');

        return array_map(function (NewsTitle $t) use ($names, $sold, $products) {
            $product = $t->linked_product_id !== '' ? $products->get($t->linked_product_id) : null;
            $stats = $sold->get($t->id);

            return [
                'id' => $t->id,
                'reference' => $t->name,
                'name' => $t->name,
                'publisher' => $t->publisher ?: null,
                'frequency' => $t->frequency?->value,
                'status' => $t->is_active ? 'active' : 'archived',
                'shopId' => NewsAccess::shopOf($t),
                'shop' => NewsAccess::shopOf($t) === null ? null : $names->shop($t->branch_id),
                'supplier' => $names->supplier($t->supplier_id),
                'coverPrice' => $t->cover_price,
                'gross' => $t->cover_price,
                'barcode' => $t->linked_barcode ?: null,
                'product' => $product?->name,
                'vat' => $product === null || $product->vat_name === null ? null : NewsTitleForm::vat((string) $product->vat_name, $product->percentage),
                'qtyIn' => (int) ($stats->qty_in ?? 0),
                'qtySold' => (int) ($stats->qty_sold ?? 0),
                'canEdit' => NewsAccess::mayEdit($t),
            ];
        }, $rows);
    }

    public function stats(Builder $query): array
    {
        $active = (clone $query)->where('is_active', true);

        return [
            self::stat('On sale', (clone $active)->count(), 'count', 'primary', 'Active titles'),
            self::stat('Every shop', (clone $active)->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', ''))->count(), 'count', 'neutral', 'Sold at all your shops'),
            self::stat('One shop', (clone $active)->whereNotNull('branch_id')->where('branch_id', '<>', '')->count(), 'count', 'neutral', 'Kept for a single shop'),
            self::stat('Archived', (clone $query)->where('is_active', false)->count(), 'count', 'warning', 'No longer on the tills'),
        ];
    }
}
