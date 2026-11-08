<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns a stylist's calendar feed off (salon W3b): the hash is cleared, so the address answers 404 for good and the
 * stylist's calendar app stops updating. Turning it on again issues a new address (IssueCalendarFeed).
 * Returns false when it was already off.
 */
final class RevokeCalendarFeed
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(SalonCalendarFeed $feed, ?User $by = null): bool
    {
        if (! $feed->enabled && $feed->token_hash === null) {
            return false;
        }

        $feed->fill([
            'enabled' => false,
            'token_hash' => null,
            'token_last4' => null,
            'revoked_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->handle('salon.calendar_feed_revoked', $feed, null, null, [
            'branch_id' => $feed->branch_id,
            'stylist_user_id' => $feed->user_id,
        ], actor: $by);

        return true;
    }
}
