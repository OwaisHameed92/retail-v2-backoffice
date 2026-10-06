<?php

namespace App\Domain\Promotions\Queries;

use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Props for the offers list (module 4.3): search, status (live now, scheduled, ended), shop (every shop, or one
 * shop's own), sorting and paging. A one-shop user sees every-shop offers and their shop's, and may change only
 * their shop's.
 */
final class PromotionList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $tenancy = app(CurrentCompany::class);
        $restricted = $tenancy->restrictedBranchId();
        $today = CarbonImmutable::now(Country::zone())->toDateString();
        $status = in_array($request->query('status'), ['live', 'scheduled', 'ended'], true) ? (string) $request->query('status') : 'all';
        $shop = is_string($request->query('shop')) ? (string) $request->query('shop') : 'all';
        $table = TableQuery::from($request)->searchable(['name', 'coupon_code'])->sortable(['name', 'effective_from', 'effective_to', 'updated_at'])
            ->defaultSort('effective_from', 'desc');

        $visible = fn (Builder $q) => $q->when($restricted !== null, fn (Builder $w) => $w->where(fn (Builder $o) => $o->whereNull('branch_id')->orWhere('branch_id', $restricted)));
        $query = PromotionRule::query()->tap($visible)
            ->when($shop === 'every', fn (Builder $q) => $q->whereNull('branch_id'))
            ->when($shop !== 'every' && $shop !== 'all', fn (Builder $q) => $q->where('branch_id', $shop))
            ->when($status !== 'all', fn (Builder $q) => self::status($q, $status, $today));

        $shops = Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->pluck('name', 'id')->all();
        $page = $table->paginate($query);
        $names = PromotionSummary::targetNames($page['data']);
        $canManage = $tenancy->can(Ability::PromotionsManage);

        $page['data'] = array_map(fn (PromotionRule $r) => [
            ...PromotionSummary::row($r, $names, $shops, $today),
            'canEdit' => $canManage && ($restricted === null || $r->branch_id === $restricted),
        ], $page['data']);

        return [
            'promotions' => $page,
            'filters' => ['status' => $status, 'shop' => $shop],
            'shops' => array_map(fn ($id, $name) => ['value' => $id, 'label' => $name], array_keys($shops), $shops),
            'counts' => [
                'live' => self::status(PromotionRule::query()->tap($visible), 'live', $today)->count(),
                'scheduled' => self::status(PromotionRule::query()->tap($visible), 'scheduled', $today)->count(),
                'shopOnly' => PromotionRule::query()->tap($visible)->whereNotNull('branch_id')->tap(fn ($q) => self::status($q, 'live', $today))->count(),
                'ended' => self::status(PromotionRule::query()->tap($visible), 'ended', $today)->count(),
            ],
            'restrictedShop' => $restricted,
            'canCreate' => $canManage,
        ];
    }

    /**
     * @param  Builder<PromotionRule>  $query
     * @return Builder<PromotionRule>
     */
    public static function status(Builder $query, string $status, string $today): Builder
    {
        return match ($status) {
            'live' => $query->where('is_active', true)->whereDate('effective_from', '<=', $today)
                ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today)),
            'scheduled' => $query->where('is_active', true)->whereDate('effective_from', '>', $today),
            default => $query->where(fn (Builder $q) => $q->where('is_active', false)->orWhereDate('effective_to', '<', $today)),
        };
    }
}
