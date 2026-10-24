<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreOldSlug;
use Carbon\CarbonImmutable;

/**
 * A store's previous addresses (OS-11, audit OSA-6): when SaveOnlineStore changes a slug, the old one is kept for
 * {@see self::DAYS} days. In that time the public store answers it with a 301 to the same page at the new address
 * (ResolveOnlineStore), so printed QR codes and shared links keep working, and no other business can take it
 * (SaveOnlineStore). Taking an address back makes it current again. Expired rows are ignored and pruned
 * (`online-store:housekeeping`); another business may then take the address.
 *
 * These reads cross businesses on purpose (an address is unique across all of them); only the slug is compared.
 */
final class StoreOldSlugs
{
    public const DAYS = 90;

    /** Keeps `$old` for the store and drops its new slug from the old ones (it is current again). */
    public static function record(OnlineStore $store, string $old): void
    {
        OnlineStoreOldSlug::withoutCompanyScope()->updateOrCreate(['slug' => $old], [
            'company_id' => $store->company_id,
            'online_store_id' => $store->id,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(self::DAYS),
        ]);

        OnlineStoreOldSlug::withoutCompanyScope()->where('online_store_id', $store->id)->where('slug', $store->slug)->delete();
    }

    /**
     * Whether another business still holds this address as an old one.
     *
     * @phpstan-impure
     */
    public static function heldElsewhere(string $companyId, string $slug): bool
    {
        return OnlineStoreOldSlug::withoutCompanyScope()->where('slug', $slug)->where('company_id', '!=', $companyId)
            ->where('expires_at', '>', CarbonImmutable::now('UTC'))->exists();
    }

    /** The current slug of the store that used this address, while it is held; else null. */
    public static function currentFor(string $slug): ?string
    {
        $old = OnlineStoreOldSlug::withoutCompanyScope()->where('slug', $slug)->where('expires_at', '>', CarbonImmutable::now('UTC'))->first();

        if ($old === null) {
            return null;
        }

        $current = OnlineStore::withoutCompanyScope()->whereKey($old->online_store_id)->value('slug');

        return is_string($current) && $current !== $slug ? $current : null;
    }

    /** Removes old addresses past their time; returns how many. */
    public static function prune(): int
    {
        return OnlineStoreOldSlug::withoutCompanyScope()->where('expires_at', '<=', CarbonImmutable::now('UTC'))->delete();
    }
}
