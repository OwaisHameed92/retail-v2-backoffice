<?php

namespace App\Domain\News\Queries;

use App\Domain\News\Support\NewsAccess;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\NewsTitleFrequency;
use App\Domain\TillData\Models\NewsTitle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Props for the news title form (module 5.8): the title as the form edits it, the shops the user may name (a
 * one-shop user: only theirs, never every shop), suppliers, the linked product and a product search for linking.
 * A title has no VAT of its own (ANSWERS-2026-10-01 §5): the till uses the linked product's VAT rate, and a title
 * without a linked product makes no VAT line on the till. UK newspapers are zero-rated, so the form suggests the
 * business's zero-rated VatRate (`percentage` 0, seed "Zero", receipt letter C).
 */
final class NewsTitleForm
{
    /** @return array<string, mixed> */
    public static function for(?NewsTitle $title, ?string $search): array
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        return [
            'title' => $title === null ? null : [
                'id' => $title->id,
                'name' => $title->name,
                'publisher' => $title->publisher ?: '',
                'frequency' => ($title->frequency ?? NewsTitleFrequency::Daily)->value,
                'supplierId' => $title->supplier_id ?: '',
                'coverPrice' => $title->cover_price,
                'linkedProductId' => $title->linked_product_id ?: '',
                'linkedBarcode' => $title->linked_barcode ?: '',
                'shopId' => NewsAccess::shopOf($title) ?? '',
                'isActive' => (bool) $title->is_active,
            ],
            'defaultShopId' => $restricted ?? '',
            'linkedProduct' => $title !== null && $title->linked_product_id !== '' ? self::products(fn ($q) => $q->where('p.id', $title->linked_product_id))[0] ?? null : null,
            'search' => $search,
            'results' => $search === null ? [] : self::products(fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', "%{$search}%")->orWhere('p.sku', 'like', "%{$search}%")
                ->orWhereIn('p.id', fn ($b) => $b->select('product_id')->from('product_barcodes')->where('company_id', app(CurrentCompany::class)->id())->where('barcode', $search)))),
            'frequencies' => array_map(fn (NewsTitleFrequency $f) => $f->value, NewsTitleFrequency::cases()),
            'zeroVatRate' => self::zeroVatRate(),
            ...NewsPage::shared(),
        ];
    }

    /**
     * @param  callable(Builder): mixed  $where
     * @return list<array{id: string, name: string, sku: string|null, barcode: string|null, price: string|null, vat: string|null, zeroRated: bool|null}>
     */
    private static function products(callable $where): array
    {
        $query = DB::table('products as p')->leftJoin('vat_rates as v', 'v.id', '=', 'p.vat_rate_id')
            ->where('p.company_id', app(CurrentCompany::class)->id())->whereNull('p.deleted_at');
        $where($query);

        $rows = $query->orderBy('p.name')->limit(20)->get(['p.id', 'p.name', 'p.sku', 'p.sell_price', 'v.name as vat_name', 'v.percentage']);
        $barcodes = DB::table('product_barcodes')->whereIn('product_id', $rows->pluck('id'))->whereNull('deleted_at')
            ->orderByDesc('is_primary')->orderBy('barcode')->get(['product_id', 'barcode'])->unique('product_id')->pluck('barcode', 'product_id');

        return $rows->map(fn ($p) => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'sku' => $p->sku ?: null,
            'barcode' => $barcodes[$p->id] ?? null,
            'price' => $p->sell_price === null ? null : (string) $p->sell_price,
            'vat' => $p->vat_name === null ? null : self::vat((string) $p->vat_name, $p->percentage),
            'zeroRated' => $p->vat_name === null ? null : self::isZero($p->percentage),
        ])->values()->all();
    }

    /**
     * The business's zero-rated VAT rate to suggest for newspapers (code C / "Zero" first), or null.
     *
     * @return array{name: string, code: string}|null
     */
    public static function zeroVatRate(): ?array
    {
        $rate = DB::table('vat_rates')->where('company_id', app(CurrentCompany::class)->id())->whereNull('deleted_at')
            ->where('percentage', 0)
            ->orderByRaw("case when code = 'C' then 0 when lower(name) = 'zero' then 1 else 2 end")->orderBy('name')
            ->first(['name', 'code']);

        return $rate === null ? null : ['name' => (string) $rate->name, 'code' => (string) $rate->code];
    }

    public static function isZero(mixed $percentage): bool
    {
        return $percentage !== null && bccomp((string) $percentage, '0', 4) === 0;
    }

    /** "Zero 0%", "Standard 20%". */
    public static function vat(string $name, mixed $percentage): string
    {
        $percent = rtrim(rtrim(number_format((float) $percentage, 4, '.', ''), '0'), '.');

        return trim($name.' '.$percent.'%');
    }
}
