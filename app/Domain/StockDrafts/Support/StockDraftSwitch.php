<?php

namespace App\Domain\StockDrafts\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;

/**
 * The `handheld.stock_drafts` switch (env `PDA_STOCK_DRAFTS`, default off) for contract proposal pda-stock-drafts.
 * Off: the handheld stock calls answer 501 NOT_AVAILABLE_YET, no draft is made and the pull is exactly as before.
 * On: drafts are made and go down to a shop's till whose `X-SSPOS-App-Version` is at least
 * `handheld.stock_drafts_min_till` (empty = every till); an older till's drafts wait.
 *
 * With a minimum set, the handheld stock calls open only for a shop whose main till (every live till when none is
 * marked main) last reported at least that version on its licence; any other shop keeps 501, so the app shows
 * "Coming soon" there instead of drafts its till would never take (owner 2026-11-04).
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

        $registers = Register::query()->where('branch_id', $shop->id);
        $main = (clone $registers)->where('is_main_till', true)->pluck('id');
        $versions = Licence::query()->live()->where('branch_id', $shop->id)
            ->whereIn('register_id', $main->isNotEmpty() ? $main : $registers->pluck('id'))
            ->pluck('last_app_version');

        return $versions->isNotEmpty() && $versions->every(fn ($version) => is_string($version) && $version !== ''
            && version_compare($version, $minimum, '>='));
    }
}
