<?php

namespace App\Domain\OnlineStore\Mail;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * Who a shop's customer emails come from (OS-10, the Emails tab): the sender name (the store's, else the business
 * name; never Switch & Save), the Reply-To (the store's, else the order address) and the look (StoreMailBrand, named
 * after the sender). Every query is scoped to the store's own business explicitly (queue workers have no signed-in
 * business).
 */
final class StoreMailIdentity
{
    public static function settings(OnlineStore $store): ?OnlineStoreMailSettings
    {
        return OnlineStoreMailSettings::withoutCompanyScope()->where('company_id', $store->company_id)
            ->where('online_store_id', $store->id)->first();
    }

    public static function senderName(Company $company, ?OnlineStoreMailSettings $settings): string
    {
        $name = trim((string) $settings?->sender_name);

        return $name !== '' && ! SenderName::isPlatform($name) ? $name : $company->name;
    }

    public static function replyTo(?OnlineStoreMailSettings $settings, ?string $orderAddress): ?string
    {
        $own = trim((string) $settings?->reply_to);

        return $own !== '' && filter_var($own, FILTER_VALIDATE_EMAIL) ? $own : $orderAddress;
    }

    /** The look, for the order's shop (address and phone in the footer), else the store's first shop (collecting or delivering, OS-12). */
    public static function brand(OnlineStore $store, Company $company, ?OnlineStoreMailSettings $settings, ?Branch $shop = null): StoreMailBrand
    {
        $shop ??= self::firstShop($store);
        $brand = StoreMailBrand::for($store, $company, $shop);

        return StoreMailBrand::make(self::senderName($company, $settings), $brand->colour, $brand->logoUrl, $brand->address, $brand->phone, $brand->storeUrl);
    }

    private static function firstShop(OnlineStore $store): ?Branch
    {
        $branchId = OnlineStoreShop::withoutCompanyScope()->where('company_id', $store->company_id)->where('online_store_id', $store->id)
            ->where(fn ($q) => $q->where('enabled', true)->orWhere('delivery_enabled', true))->orderBy('created_at')->value('branch_id');

        return $branchId === null ? null : Branch::withoutCompanyScope()->where('company_id', $store->company_id)->find($branchId);
    }
}
