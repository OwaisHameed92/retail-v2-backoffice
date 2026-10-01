<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Labels\Actions\QueueChangedLabels;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ends a shop's own price for a product (or one unit) now (module 4.3), so the shop sells at the business price
 * again: every row of that shop and product (unit) live at that moment gets `validToUtc` = now, **including rows the
 * shop's till set** (SHOP-OR-EVERY-SHOP.md: "end them on the portal and send the rows down"). Rows that start later
 * are left alone, as the till does. Returns how many rows were ended (0 = the shop had no price of its own).
 */
final class EndShopPrice
{
    public function __construct(private readonly RecordAudit $audit, private readonly QueueChangedLabels $labels) {}

    public function handle(Branch $branch, Product $product, ?string $productUnitId = null, ?CarbonImmutable $at = null): int
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc()->startOfSecond();

        return DB::transaction(function () use ($branch, $product, $productUnitId, $at) {
            $rows = BranchPrice::query()->forBranch($branch)->forProduct($product->id, $productUnitId)
                ->liveAt($at->format('Y-m-d H:i:s'))->lockForUpdate()->get();

            foreach ($rows as $row) {
                $row->forceFill(['valid_to_utc' => $at, 'row_version' => (int) $row->row_version + 1])->save();
            }

            if ($rows->isNotEmpty()) {
                $this->audit->handle('price.shop_ended', $product, ['price' => $rows->first()->price], ['valid_to_utc' => $at->toIso8601ZuluString()], [
                    'name' => $product->name, 'shop' => $branch->name, 'branch_id' => $branch->id, 'product_unit_id' => $productUnitId,
                    'branch_price_ids' => $rows->pluck('id')->all(),
                ]);

                if ($productUnitId === null) {
                    $this->labels->shopPriceEnded($branch, $product); // Shelf labels (gap #6).
                }
            }

            return $rows->count();
        });
    }
}
