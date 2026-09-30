<?php

namespace App\Domain\Pricing\Support;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reading shop prices as a till would (module 4.3): a row is live from `validFromUtc` until `validToUtc`
 * (exclusive); among live rows of a shop and product (unit) the latest start wins, then the higher id.
 * The shops a user sees: every shop, or only the one a one-shop user is limited to (module 3.3).
 */
final class ShopPrices
{
    /**
     * Shops shown on the price screens: open shops, plus the user's own shop even when closed.
     *
     * @return Collection<int, Branch>
     */
    public static function shops(): Collection
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return Branch::query()
            ->when($restricted !== null, fn ($q) => $q->whereKey($restricted), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get(['id', 'company_id', 'name', 'code', 'is_active']);
    }

    /**
     * The winning live row per product, shop and unit ('' = the base unit).
     *
     * @param  list<string>  $productIds
     * @param  list<string>  $branchIds
     * @return array<string, array<string, array<string, BranchPrice>>>
     */
    public static function live(array $productIds, array $branchIds, ?CarbonImmutable $at = null): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->format('Y-m-d H:i:s');
        $winners = [];

        $rows = BranchPrice::query()->whereIn('product_id', $productIds)->whereIn('branch_id', $branchIds)
            ->where('valid_from_utc', '<=', $at)
            ->where(fn ($q) => $q->whereNull('valid_to_utc')->orWhere('valid_to_utc', '>', $at))
            ->orderByDesc('valid_from_utc')->orderByDesc('id')->get();

        foreach ($rows as $row) {
            $winners[$row->product_id][(string) $row->branch_id][$row->product_unit_id ?? ''] ??= $row;
        }

        return $winners;
    }

    /** live, scheduled (starts later), ended, or cancelled (ends when it starts: never live). */
    public static function status(BranchPrice $row, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now('UTC');

        return match (true) {
            $row->valid_to_utc !== null && $row->valid_to_utc->lessThanOrEqualTo($row->valid_from_utc) => 'cancelled',
            $row->valid_from_utc->greaterThan($at) => 'scheduled',
            $row->valid_to_utc !== null && $row->valid_to_utc->lessThanOrEqualTo($at) => 'ended',
            default => 'live',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(BranchPrice $row, ?string $shopName = null, ?string $unitName = null): array
    {
        return [
            'id' => $row->id,
            'branchId' => $row->branch_id,
            'shop' => $shopName,
            'unitId' => $row->product_unit_id,
            'unit' => $unitName,
            'price' => (string) $row->price,
            'validFrom' => $row->valid_from_utc->toIso8601ZuluString(),
            'validTo' => $row->valid_to_utc?->toIso8601ZuluString(),
            'status' => self::status($row),
            // Who changed the row last: its shop's till (origin) or the portal (made or ended here).
            'changedAt' => $row->origin_branch_id !== null ? 'shop' : 'portal',
            'updatedAt' => $row->updated_at?->toIso8601ZuluString(),
        ];
    }
}
