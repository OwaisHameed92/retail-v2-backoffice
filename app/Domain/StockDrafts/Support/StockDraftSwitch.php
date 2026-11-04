<?php

namespace App\Domain\StockDrafts\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Database\Eloquent\Collection;

/**
 * The `handheld.stock_drafts` switch (env `PDA_STOCK_DRAFTS`, default off) for contract proposal pda-stock-drafts.
 * Off: the handheld stock calls answer 501 NOT_AVAILABLE_YET, no draft is made and the pull is exactly as before.
 * On: drafts are made and go down to a shop's till whose `X-SSPOS-App-Version` is at least
 * `handheld.stock_drafts_min_till` (empty = every till); an older till's drafts wait.
 *
 * With a minimum set, the handheld stock calls open only for a shop whose main till (every live till when none is
 * marked main) last reported at least that version on its licence; any other shop keeps 501, so the app shows
 * "Coming soon" there instead of drafts its till would never take (owner 2026-11-04).
 *
 * The stock calls are part of the `purchasing` licence feature (owner 2026-11-04): a shop whose main till's licence
 * (every live till's when none is marked main) leaves it out gets 403 FEATURE_NOT_INCLUDED (`includedForShop`).
 */
final class StockDraftSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('handheld.stock_drafts', false);
    }

    /** Whether the pull of a till reporting this app version carries stock drafts. */
    public static function servesTill(string $appVersion): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $minimum = trim((string) config('handheld.stock_drafts_min_till', ''));

        return $minimum === '' || ($appVersion !== '' && version_compare($appVersion, $minimum, '>='));
    }

    /** Whether the handheld stock calls are open for this shop (on, and its till new enough). */
    public static function servesShop(Branch $shop): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $minimum = trim((string) config('handheld.stock_drafts_min_till', ''));

        if ($minimum === '') {
            return true;
        }

        $versions = self::shopLicences($shop)->pluck('last_app_version');

        return $versions->isNotEmpty() && $versions->every(fn ($version) => is_string($version) && $version !== ''
            && version_compare($version, $minimum, '>='));
    }

    /** Whether the shop's till licence includes the `purchasing` feature the handheld stock calls belong to. */
    public static function includedForShop(Branch $shop): bool
    {
        $licences = self::shopLicences($shop);

        return $licences->isNotEmpty() && $licences->every(fn (Licence $licence) => $licence->features->contains(Feature::Purchasing));
    }

    /**
     * The live licences that speak for the shop: its main till's, or every live till's when none is marked main.
     *
     * @return Collection<int, Licence>
     */
    private static function shopLicences(Branch $shop): Collection
    {
        $registers = Register::query()->where('branch_id', $shop->id);
        $main = (clone $registers)->where('is_main_till', true)->pluck('id');

        return Licence::query()->live()->where('branch_id', $shop->id)
            ->whereIn('register_id', $main->isNotEmpty() ? $main : $registers->pluck('id'))
            ->get(['id', 'register_id', 'features', 'last_app_version']);
    }
}
