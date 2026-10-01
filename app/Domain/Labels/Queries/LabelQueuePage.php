<?php

namespace App\Domain\Labels\Queries;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Labels\Support\LabelStocks;
use App\Domain\Labels\Support\LabelTemplates;
use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Props for the shelf-label screen (gap #6): one shop's queue (`view=waiting`) or its printed labels
 * (`view=printed`), with search, a reason filter and sorting; counts; the shop's templates and the label stocks; the
 * departments and suppliers to add by. A one-shop user sees only their shop; others pick a shop (default: the top-bar
 * shop, else the first).
 */
final class LabelQueuePage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $shops = ShopPrices::shops();
        $shop = self::shop($request, $shops);
        $view = $request->query('view') === 'printed' ? 'printed' : 'waiting';
        $reason = LabelReason::tryFrom((string) $request->query('reason'));

        $props = [
            'shops' => $shops->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'shop' => $shop === null ? null : ['id' => $shop->id, 'name' => $shop->name],
            'restrictedShop' => app(CurrentCompany::class)->restrictedBranchId(),
            'filters' => ['view' => $view, 'reason' => $reason?->value],
            'reasons' => array_map(fn (LabelReason $r) => ['value' => $r->value, 'label' => $r->label()], LabelReason::cases()),
            'stocks' => LabelStocks::all(),
            'items' => ['data' => [], 'meta' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'lastPage' => 1, 'search' => null, 'sort' => null, 'direction' => 'desc']],
            'counts' => ['waiting' => 0, 'priceChanges' => 0, 'offers' => 0, 'manual' => 0, 'later' => 0, 'printedWeek' => 0],
            'templates' => [],
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => (string) $d->name])->all(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => (string) $s->name])->all(),
        ];

        if ($shop === null) {
            return $props;
        }

        $table = TableQuery::from($request)->sortable(['queued_at', 'printed_at'])->defaultSort($view === 'printed' ? 'printed_at' : 'queued_at', 'desc');
        $search = $table->search();
        $query = LabelQueueItem::query()->with('product:id,name,sku,sell_price')->where('branch_id', $shop->id)
            ->where('pending', $view === 'waiting')
            ->when($view === 'printed', fn (Builder $q) => $q->whereNotNull('printed_at'))
            ->when($reason !== null, fn (Builder $q) => $q->where('reason', $reason?->value))
            ->when($search !== null, fn (Builder $q) => $q->whereIn('product_id', Product::query()->select('id')->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', $search.'%')
                ->orWhereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', $search)))));

        $page = $table->paginate($query);
        $live = ShopPrices::live(array_map(fn (LabelQueueItem $i) => $i->product_id, $page['data']), [$shop->id]);
        $page['data'] = array_map(fn (LabelQueueItem $i) => self::row($i, isset($live[$i->product_id][$shop->id]['']) ? (string) $live[$i->product_id][$shop->id]['']->price : null), $page['data']);
        $waiting = LabelQueueItem::query()->where('branch_id', $shop->id)->where('pending', true);
        $byReason = (clone $waiting)->selectRaw('reason, count(*) as n')->groupBy('reason')->pluck('n', 'reason');

        return [
            ...$props,
            'items' => $page,
            'counts' => [
                'waiting' => (int) $byReason->sum(),
                'priceChanges' => (int) (($byReason['priceChange'] ?? 0) + ($byReason['shopPrice'] ?? 0) + ($byReason['shopPriceEnded'] ?? 0)),
                'offers' => (int) (($byReason['promotionStarted'] ?? 0) + ($byReason['promotionEnded'] ?? 0)),
                'manual' => (int) ($byReason['manual'] ?? 0),
                'later' => (clone $waiting)->where('due_at', '>', now('UTC')->format('Y-m-d H:i:s'))->count(),
                'printedWeek' => LabelQueueItem::query()->where('branch_id', $shop->id)->where('printed_at', '>=', now('UTC')->subDays(7)->format('Y-m-d H:i:s'))->count(),
            ],
            'templates' => LabelTemplates::forShop($shop->id),
        ];
    }

    /**
     * @param  Collection<int, Branch>  $shops
     */
    private static function shop(Request $request, Collection $shops): ?Branch
    {
        $asked = $request->query('shop');
        $current = app(ResolveCurrentBranch::class)->handle($request->session());

        return $shops->firstWhere('id', is_string($asked) ? $asked : null)
            ?? ($current !== null ? $shops->firstWhere('id', $current->id) : null)
            ?? $shops->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(LabelQueueItem $i, ?string $shopPrice): array
    {
        return [
            'id' => $i->id,
            'productId' => $i->product_id,
            'name' => (string) ($i->product->name ?? 'Product removed'),
            'sku' => $i->product?->sku,
            'price' => $shopPrice ?? ($i->product !== null ? (string) $i->product->sell_price : null),
            'ownPrice' => $shopPrice !== null,
            'reason' => $i->reason->value,
            'reasonLabel' => $i->reason->label(),
            'detail' => $i->detail,
            'copies' => $i->copies,
            'timesQueued' => $i->times_queued,
            'queuedAt' => $i->queued_at->toIso8601ZuluString(),
            'dueAt' => $i->due_at?->toIso8601ZuluString(),
            'printedAt' => $i->printed_at?->toIso8601ZuluString(),
        ];
    }
}
