<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\PdaPage;
use App\Domain\Handheld\Support\PurchaseOrderView;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The handheld user's shop's purchase orders: head-office orders (drafted on the portal or the app) and the till's
 * own, newest first, with their lines (PurchaseOrderView). Never another shop's or business's order: an id from
 * there is "not found".
 */
final class HandheldPurchaseOrders
{
    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function page(Branch $shop, ?string $status, ?string $search, int $perPage, int $page): array
    {
        $status = PurchaseOrderStatus::tryFrom((string) $status);
        $search = trim((string) $search);

        $query = $this->query($shop)
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status?->value))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('reference', 'like', "%{$search}%")->orWhere('order_no', 'like', "%{$search}%")))
            ->orderByDesc('created_at')->orderByDesc('id');

        return PdaPage::of($query, $perPage, $page, fn ($orders) => PurchaseOrderView::many($orders));
    }

    /**
     * @throws ModelNotFoundException<PurchaseOrder>
     */
    public function find(Branch $shop, string $id): PurchaseOrder
    {
        return $this->query($shop)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(PurchaseOrder $order): array
    {
        return PurchaseOrderView::many(collect([$order]))[0];
    }

    /**
     * @return Builder<PurchaseOrder>
     */
    private function query(Branch $shop): Builder
    {
        return PurchaseOrder::query()->where('branch_id', $shop->id);
    }
}
