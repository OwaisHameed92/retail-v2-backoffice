<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\FixedAsset;
use App\Domain\TillData\Models\FixedAssetCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The shops' fixed assets (module 5.5), read only: `FixedAsset` is branch-owned and its category hub-owned but made
 * on the tills; the portal only shows them. Not limited by dates (an asset register), only by shop.
 */
final class FixedAssetList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, AccountsFilters $filters): array
    {
        $table = TableQuery::from($request)->searchable(['fixed_assets.name', 'fixed_assets.code'])
            ->sortable(['purchase_date', 'name', 'purchase_cost_amount'])->defaultSort('purchase_date', 'desc')->defaultPerPage(25);
        $query = fn () => FixedAsset::query()->when($filters->shop !== null, fn (Builder $q) => $q->where('branch_id', $filters->shop));
        $page = $table->paginator($query());
        /** @var list<FixedAsset> $assets */
        $assets = $page->items();
        $shops = CashLookup::shops(array_map(fn (FixedAsset $a) => $a->branch_id, $assets));
        $categories = FixedAssetCategory::query()->withTrashed()->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        $active = $query()->where(fn (Builder $q) => $q->whereNull('disposal_date'))->get(['purchase_cost_amount']);

        return [
            'assets' => [
                'data' => array_map(fn (FixedAsset $a) => [
                    'id' => $a->id,
                    'name' => (string) $a->name !== '' ? (string) $a->name : 'Unnamed asset',
                    'code' => (string) $a->code !== '' ? (string) $a->code : null,
                    'category' => CashLookup::name($categories, $a->fixed_asset_category_id),
                    'shop' => CashLookup::name($shops, $a->branch_id),
                    'purchaseDate' => $a->purchase_date->format('Y-m-d'),
                    'cost' => CashLookup::money($a->purchase_cost_amount),
                    'usefulLifeYears' => $a->useful_life_years,
                    'depreciationRate' => $a->depreciation_rate_percent !== null ? Money::normalise($a->depreciation_rate_percent, 2) : null,
                    'residual' => CashLookup::money($a->residual_value_amount),
                    'disposalDate' => $a->disposal_date?->format('Y-m-d'),
                    'disposalProceeds' => CashLookup::money($a->disposal_proceeds_amount),
                    'isActive' => (bool) $a->is_active,
                    'notes' => (string) $a->notes !== '' ? (string) $a->notes : null,
                ], $assets),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'totals' => [
                'held' => $active->count(),
                'cost' => Money::sum($active->map(fn (FixedAsset $a) => $a->purchase_cost_amount ?? '0')),
            ],
        ];
    }
}
