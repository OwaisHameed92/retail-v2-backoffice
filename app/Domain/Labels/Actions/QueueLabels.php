<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;

/**
 * Puts products in shops' shelf-label queues (gap #6). Deduped per shop and product: a product already waiting gets
 * the new reason, detail and due time (the latest change wins, `times_queued` counts them); a printed one is queued
 * again in place. Only active products and open shops. `$branchIds` null = every open shop of the company.
 * `$skipOwnPrice`: leave out shops with a live own price for the product (a business price change does not move it).
 *
 * Works with or without a current company (explicit `company_id`), so price actions, jobs and commands can call it.
 * Returns how many shop × product rows were queued.
 */
final class QueueLabels
{
    /**
     * @param  list<string>  $productIds
     * @param  list<string>|null  $branchIds
     */
    public function handle(
        string $companyId,
        array $productIds,
        ?array $branchIds,
        LabelReason $reason,
        ?string $detail = null,
        ?CarbonImmutable $dueAt = null,
        bool $skipOwnPrice = false,
    ): int {
        $branches = Branch::withoutCompanyScope()->where('company_id', $companyId)->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds))->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($branches === [] || $productIds === []) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $due = $dueAt !== null && $dueAt->greaterThan($now) ? $dueAt->utc()->startOfSecond() : null;
        $userId = auth('web')->id();
        $queued = 0;

        foreach (array_chunk(array_values(array_unique($productIds)), 200) as $chunk) {
            $active = Product::withoutCompanyScope()->where('company_id', $companyId)->whereIn('id', $chunk)->where('is_active', true)
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
            $own = $skipOwnPrice ? $this->ownPrices($companyId, $active, $branches, $now) : [];
            $existing = LabelQueueItem::withoutCompanyScope()->where('company_id', $companyId)->whereIn('product_id', $active)
                ->whereIn('branch_id', $branches)->get()->keyBy(fn (LabelQueueItem $i) => $i->branch_id.'|'.$i->product_id);

            foreach ($active as $productId) {
                foreach ($branches as $branchId) {
                    if (isset($own[$productId.'|'.$branchId])) {
                        continue;
                    }

                    $item = $existing->get($branchId.'|'.$productId) ?? (new LabelQueueItem)->forceFill([
                        'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'copies' => 1, 'times_queued' => 0,
                    ]);

                    $item->forceFill([
                        'reason' => $reason, 'detail' => $detail !== null ? mb_substr($detail, 0, 190) : null, 'due_at' => $due,
                        'queued_at' => $now, 'queued_by_user_id' => $userId,
                        'times_queued' => $item->pending ?? false ? $item->times_queued + 1 : 1,
                        'pending' => true,
                    ])->save();
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * "product|branch" pairs with a live own (base unit) shop price.
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $branchIds
     * @return array<string, true>
     */
    private function ownPrices(string $companyId, array $productIds, array $branchIds, CarbonImmutable $now): array
    {
        return BranchPrice::withoutCompanyScope()->where('company_id', $companyId)->whereIn('product_id', $productIds)
            ->whereIn('branch_id', $branchIds)->whereNull('product_unit_id')->liveAt($now->format('Y-m-d H:i:s'))->reorder()
            ->get(['product_id', 'branch_id'])->mapWithKeys(fn (BranchPrice $r) => [$r->product_id.'|'.$r->branch_id => true])->all();
    }
}
