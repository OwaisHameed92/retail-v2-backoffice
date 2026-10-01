<?php

namespace App\Domain\Calendar\Support;

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\Tenancy\Models\Branch;

/**
 * The one `shop.trading_hours` line the tills get (ANSWERS-2026-10-01 §3): a company-scope Text setting, at most
 * 200 characters, shown as written on the customer screen and not parsed by the till. Each shop's week is kept on
 * the portal; the line is the first shop's week (the oldest active shop with hours set). When shops' weeks differ,
 * the calendar page says that tills show one line for the business. Runs inside the company scope.
 */
final class BusinessHoursLine
{
    public const MAX = 200;

    /**
     * @return array{text: string|null, shopId: string|null, shopName: string|null, differs: bool}
     */
    public static function current(): array
    {
        $weeks = ShopOpeningHour::query()->get()->groupBy('branch_id');
        $shops = Branch::query()->whereIn('id', $weeks->keys()->all())->orderByDesc('is_active')->orderBy('created_at')->orderBy('name')
            ->get(['id', 'name', 'is_active', 'created_at']);
        $texts = [];

        foreach ($shops as $shop) {
            $texts[(string) $shop->id] = (new WeeklyHours($weeks->get($shop->id)->mapWithKeys(fn (ShopOpeningHour $r) => [
                $r->weekday => $r->is_closed ? null : ['opens' => (string) $r->opens_at, 'closes' => (string) $r->closes_at],
            ])->all()))->text();
        }

        $first = $shops->first();

        return [
            'text' => $first === null ? null : $texts[(string) $first->id],
            'shopId' => $first?->id,
            'shopName' => $first?->name,
            'differs' => count(array_unique($texts)) > 1,
        ];
    }
}
