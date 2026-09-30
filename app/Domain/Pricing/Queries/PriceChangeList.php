<?php

namespace App\Domain\Pricing\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PriceChangeBatch;
use App\Domain\TillData\Models\PriceChangeLine;
use App\Domain\TillData\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Props for the price change batches the shops' tills made (module 4.3). They are branch-owned (ownership.json):
 * the portal shows them and never changes them. `?batch=<id>` adds a preview of that batch's lines (old → new
 * price, this shop or every shop). A one-shop user sees only their shop's batches.
 */
final class PriceChangeList
{
    private const PREVIEW_LIMIT = 500;

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $shops = Branch::query()->pluck('name', 'id')->all();
        $table = TableQuery::from($request)->searchable(['reference', 'name', 'reason'])->sortable(['created_at', 'effective_at', 'reference'])
            ->defaultSort('created_at', 'desc');
        $status = is_string($request->query('status')) ? (string) $request->query('status') : null;

        $query = PriceChangeBatch::query()->forBranch($restricted)
            ->when(in_array($status, ['draft', 'approved', 'scheduled', 'live', 'cancelled'], true), fn (Builder $q) => $q->where('status', $status));
        $page = $table->paginate($query);
        $ids = array_map(fn (PriceChangeBatch $b) => $b->id, $page['data']);
        $lineCounts = PriceChangeLine::query()->whereIn('batch_id', $ids)->selectRaw('batch_id, count(*) as n')->groupBy('batch_id')->pluck('n', 'batch_id')->all();

        $page['data'] = array_map(fn (PriceChangeBatch $b) => [
            'id' => $b->id, 'reference' => $b->reference, 'name' => $b->name, 'shop' => $shops[$b->branch_id ?? ''] ?? null,
            'status' => $b->status?->value, 'source' => $b->source, 'reason' => $b->reason,
            'effectiveAt' => $b->effective_at?->toIso8601ZuluString(), 'createdAt' => $b->created_at?->toIso8601ZuluString(),
            'lines' => (int) ($lineCounts[$b->id] ?? 0),
        ], $page['data']);

        $batchId = $request->query('batch');
        $batch = is_string($batchId) && preg_match('/^[0-9A-Za-z]{26}$/', $batchId) === 1
            ? PriceChangeBatch::query()->forBranch($restricted)->find($batchId) : null;

        return [
            'batches' => $page,
            'filters' => ['status' => $status],
            'preview' => $batch === null ? null : self::preview($batch, $shops),
        ];
    }

    /**
     * @param  array<string, string>  $shops
     * @return array<string, mixed>
     */
    private static function preview(PriceChangeBatch $batch, array $shops): array
    {
        $lines = PriceChangeLine::query()->where('batch_id', $batch->id)->orderBy('product_name')->limit(self::PREVIEW_LIMIT)->get();
        $current = Product::query()->whereIn('id', $lines->pluck('product_id')->unique()->all())->pluck('sell_price', 'id')->all();

        return [
            'id' => $batch->id, 'reference' => $batch->reference, 'name' => $batch->name, 'status' => $batch->status?->value,
            'shop' => $shops[$batch->branch_id ?? ''] ?? null, 'reason' => $batch->reason,
            'lines' => $lines->map(fn (PriceChangeLine $l) => [
                'id' => $l->id, 'productId' => $l->product_id, 'product' => $l->product_name, 'oldPrice' => (string) $l->old_price,
                'newPrice' => (string) $l->new_price, 'changePercent' => $l->change_percent === null ? null : (string) $l->change_percent,
                'where' => $l->price_branch_id === null ? null : ($shops[$l->price_branch_id] ?? 'Another shop'),
                'status' => $l->status?->value, 'businessPriceNow' => isset($current[$l->product_id]) ? (string) $current[$l->product_id] : null,
            ])->values()->all(),
            'limited' => $lines->count() === self::PREVIEW_LIMIT,
        ];
    }
}
