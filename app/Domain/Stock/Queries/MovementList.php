<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Support\MovementKinds;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Models\TillUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The movements page (module 5.1): one keyset page of the filtered movements with product, shop, staff and reason
 * names, the totals per kind for the same filters, and the product picked (if any).
 */
final class MovementList
{
    public const PER_PAGE = [25, 50, 100];

    /** @return array<string, mixed> */
    public static function for(Request $request, StockFilters $f): array
    {
        $perPage = in_array((int) $request->query('perPage'), self::PER_PAGE, true) ? (int) $request->query('perPage') : 50;
        $query = MovementSearch::query($f);
        $page = MovementSearch::page($query, self::cursor($request, 'after'), self::cursor($request, 'before'), $perPage);
        $product = $f->product !== null ? Product::query()->find($f->product, ['id', 'name', 'sku']) : null;

        return [
            'movements' => [
                'data' => self::rows($page['rows']),
                'older' => $page['older'],
                'newer' => $page['newer'],
                'perPage' => $perPage,
            ],
            'summary' => MovementSearch::summary($query),
            'product' => $product !== null ? ['id' => $product->id, 'name' => $product->name, 'sku' => (string) ($product->sku ?? '')] : null,
            'typeOptions' => MovementKinds::options(),
        ];
    }

    /**
     * @param  Collection<int, StockMovement>  $rows
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $rows): array
    {
        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique()->all())->get(['id', 'name', 'sku'])->keyBy('id');
        $staff = TillUser::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $reasons = Reason::query()->withTrashed()->whereIn('id', $rows->pluck('reason_id')->filter()->unique()->all())->pluck('text', 'id');
        $shops = StockNames::shops();

        return $rows->map(function (StockMovement $m) use ($products, $staff, $reasons, $shops) {
            $type = $m->type->value ?? $m->getRawOriginal('type');
            $product = $products[$m->product_id] ?? null;

            return [
                'id' => $m->id,
                'at' => $m->at->toIso8601ZuluString(),
                'productId' => $m->product_id,
                'product' => $product !== null && $product->name !== '' ? $product->name : 'Unknown product',
                'sku' => (string) ($product->sku ?? ''),
                'shop' => $shops[$m->branch_id] ?? 'Unknown shop',
                'type' => $type,
                'typeLabel' => MovementKinds::label(is_string($type) ? $type : null),
                'group' => MovementKinds::group(is_string($type) ? $type : null),
                'qty' => Money::normalise($m->qty_delta ?? 0, 4),
                'before' => Money::normalise($m->qty_before ?? 0, 4),
                'after' => Money::normalise($m->qty_after ?? 0, 4),
                'unitCost' => Money::normalise($m->unit_cost ?? 0, 4),
                'value' => Money::round(Money::mul($m->qty_delta ?? 0, $m->unit_cost ?? 0, 4), 2),
                'reason' => $m->reason_id !== null ? ($reasons[$m->reason_id] ?? null) : null,
                'note' => (string) ($m->note ?? '') !== '' ? $m->note : null,
                'refType' => (string) ($m->ref_type ?? '') !== '' ? $m->ref_type : null,
                'refId' => (string) ($m->ref_id ?? '') !== '' ? $m->ref_id : null,
                'staff' => $m->user_id !== '' ? ($staff[$m->user_id] ?? null) : null,
            ];
        })->values()->all();
    }

    private static function cursor(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && strlen($value) <= 120 ? $value : null;
    }
}
