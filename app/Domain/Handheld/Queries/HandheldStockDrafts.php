<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\PdaPage;
use App\Domain\Handheld\Support\StockDraftView;
use App\Domain\StockDrafts\Actions\ReconcileStockDrafts;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The handheld user's shop's stock drafts of one kind (adjustments, stock takes, returns, deliveries), newest
 * first, with the till's latest outcome read first (ReconcileStockDrafts). Filters: the app's `client_reference`,
 * `product_id` (adjustments), `purchase_order_id` (returns, deliveries). Another shop's or business's draft is
 * "not found".
 */
final class HandheldStockDrafts
{
    public function __construct(private readonly ReconcileStockDrafts $reconcile) {}

    /**
     * @param  array{client_reference?: string|null, product_id?: string|null, purchase_order_id?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function page(Branch $shop, StockDraftKind $kind, array $filters, int $perPage, int $page): array
    {
        $this->refresh($shop);
        $reference = mb_strtolower(trim((string) ($filters['client_reference'] ?? '')));
        $product = trim((string) ($filters['product_id'] ?? ''));
        $order = trim((string) ($filters['purchase_order_id'] ?? ''));

        $query = $this->query($shop, $kind)->with('lines')
            ->when($reference !== '', fn (Builder $q) => $q->where('client_reference', $reference))
            ->when($product !== '', fn (Builder $q) => $q->where('product_id', $product))
            ->when($order !== '', fn (Builder $q) => $q->where('purchase_order_id', $order))
            ->orderByDesc('created_at')->orderByDesc('id');

        return PdaPage::of($query, $perPage, $page, fn ($drafts) => $drafts->map(fn (StockDraft $d) => StockDraftView::of($d))->values()->all());
    }

    /**
     * @throws ModelNotFoundException<StockDraft>
     */
    public function find(Branch $shop, StockDraftKind $kind, string $id): StockDraft
    {
        $this->refresh($shop);

        return $this->query($shop, $kind)->with('lines')->findOrFail($id);
    }

    /**
     * Deliveries booked against one order from the handheld (any state), newest first.
     *
     * @return list<StockDraft>
     */
    public function receipts(Branch $shop, string $orderId): array
    {
        $this->refresh($shop);

        return $this->query($shop, StockDraftKind::GoodsReceipt)->with('lines')->where('purchase_order_id', $orderId)
            ->orderByDesc('happened_at')->orderByDesc('id')->get()->all();
    }

    /** The till's latest outcomes for this shop. */
    public function refresh(Branch $shop): void
    {
        $this->reconcile->handle($shop->company_id, $shop->id);
    }

    /**
     * @return Builder<StockDraft>
     */
    private function query(Branch $shop, StockDraftKind $kind): Builder
    {
        return StockDraft::query()->where('branch_id', $shop->id)->where('kind', $kind->value);
    }
}
