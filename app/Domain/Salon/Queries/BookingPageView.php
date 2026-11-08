<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * The public booking page's data (W3a, salon pack README §4.1, §6): rendered from the page the till published, in
 * the shop's name. Services with their options (each with its own price and minutes, and every online stylist's own),
 * add-ons, the stylists (first names only) and "Any stylist", the client groups to filter by, the questions, terms
 * and deposit note, and whether the shop is open, in "request only" or closed (with its phone). Times are fetched
 * separately for the client's basket (BookingTimes). Never the manage-link key.
 */
final class BookingPageView
{
    /**
     * @return array<string, mixed>
     */
    public static function props(SalonOnlineShop $shop, CarbonImmutable $now): array
    {
        $page = (array) $shop->page;
        $rules = $page['rules'];
        $branch = Branch::withoutCompanyScope()->find($shop->branch_id);
        $groups = array_values(array_unique(array_filter(array_column($page['services'], 'clientGroup'), fn (string $g) => in_array($g, ['men', 'women', 'kids'], true))));

        return [
            'shop' => self::shop($shop, $branch),
            'status' => SalonShopStatus::booking($shop, $now),
            'queueOpen' => SalonShopStatus::queueOpen($shop, $now),
            'rules' => [
                'autoAccept' => $rules['autoAccept'],
                'askPatchTest' => $rules['askPatchTest'],
                'questions' => $rules['questions'],
                'terms' => $rules['terms'],
                'depositNote' => $rules['depositNote'],
                'minNoticeMin' => $rules['minNoticeMin'],
                'holdMin' => $rules['holdMin'],
            ],
            'groups' => $groups,
            'services' => array_map(fn (array $s) => [
                'productId' => $s['productId'],
                'name' => $s['name'],
                'description' => $s['description'],
                'categoryName' => $s['categoryName'],
                'clientGroup' => $s['clientGroup'],
                'priceIsFrom' => $s['priceIsFrom'],
                'needsPatchTest' => $s['needsPatchTest'],
                'depositAmount' => $s['depositAmount'],
                'options' => array_map(fn (array $o) => [
                    'productId' => $o['productId'],
                    'label' => $o['label'],
                    'price' => $o['price'],
                    'durationMin' => $o['durationMin'],
                    'byStylist' => $o['byStylist'],
                ], array_values(array_filter($s['options'], fn (array $o) => $o['byStylist'] !== []))),
                'addOns' => $s['addOns'],
            ], array_values(array_filter($page['services'], fn (array $s) => array_filter($s['options'], fn (array $o) => $o['byStylist'] !== []) !== []))),
            'stylists' => array_map(fn (array $s) => ['userId' => $s['userId'], 'firstName' => $s['firstName'], 'levelName' => $s['levelName'], 'group' => $s['group']], $page['stylists']),
        ];
    }

    /**
     * The shop as every public salon page shows it: its name (as the till publishes it), phone and addresses.
     *
     * @return array{name: string, phone: string, urls: array{bookingUrl: string, queueJoinUrl: string, manageUrl: string}}
     */
    public static function shop(SalonOnlineShop $shop, ?Branch $branch = null): array
    {
        $branch ??= Branch::withoutCompanyScope()->find($shop->branch_id);
        $name = (string) ($shop->page['shopName'] ?? '');

        return [
            'name' => $name !== '' ? $name : (string) $branch?->name,
            'phone' => (string) $branch?->phone,
            'urls' => SalonOnlineShops::addresses($shop),
        ];
    }
}
