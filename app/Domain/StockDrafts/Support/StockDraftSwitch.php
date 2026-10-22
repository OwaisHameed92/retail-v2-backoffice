<?php

namespace App\Domain\StockDrafts\Support;

/**
 * The `handheld.stock_drafts` switch (env `PDA_STOCK_DRAFTS`, default off) for contract proposal pda-stock-drafts.
 * Off: the handheld stock calls answer 501 NOT_AVAILABLE_YET, no draft is made and the pull is exactly as before.
 * On: drafts are made and go down to a shop's till whose `X-SSPOS-App-Version` is at least
 * `handheld.stock_drafts_min_till` (empty = every till); an older till's drafts wait.
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
}
