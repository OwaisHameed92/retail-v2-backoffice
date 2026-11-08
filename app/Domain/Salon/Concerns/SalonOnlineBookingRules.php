<?php

namespace App\Domain\Salon\Concerns;

use App\Domain\Salon\Exceptions\SalonBookingRefused;
use App\Domain\Salon\Support\SalonBookingFields;
use Illuminate\Database\Eloquent\Model;

/**
 * The portal's side of the `SalonOnlineBooking` field ownership (SalonBookingFields; W2, the writer is W3's booking
 * page). Every portal save of the model goes through these checks; the sync applier writes till rows without
 * Eloquent, so a till's answer is never stopped here.
 *
 * A portal save is refused (SalonBookingRefused) when it:
 * - sets an answer status (accepted / declined) or any status the contract does not give the portal;
 * - sets one of the till's answer members (decidedAt, decidedByUserId, decisionReason; appointmentId on `kind: new`);
 * - changes a request that is no longer pending (the till answered it, or it was cancelled / expired): "once the till
 *   has answered, any later portal change to that row is refused" (README Part C; send a new `kind: cancel` instead);
 * - has no shop, or moves the request to another shop;
 * - deletes a request that is no longer pending.
 *
 * @mixin Model
 */
trait SalonOnlineBookingRules
{
    /** Bookkeeping columns a save may change without being a content change. */
    private const BOOKKEEPING = [
        'hub_version', 'hub_edited_at', 'hub_hash', 'origin_branch_id', 'updated_at', 'created_at', 'row_version',
        'synced_at', 'sync_seq', 'portal_received_at',
    ];

    public static function bootSalonOnlineBookingRules(): void
    {
        static::saving(function (Model $model): void {
            if ($model->exists && $model->isDirty('hub_version') && $model->getAttribute('hub_version') !== null) {
                return; // a pull version being stamped, not a portal change
            }

            self::checkPortalSave($model);
        });

        static::deleting(function (Model $model): void {
            if ($model->getRawOriginal('status') !== SalonBookingFields::OPEN_STATUS) {
                throw new SalonBookingRefused('This online booking request has been answered or has ended; send a new cancel request instead.');
            }
        });
    }

    private static function checkPortalSave(Model $model): void
    {
        $status = $model->getAttribute('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;

        if (! in_array($status, SalonBookingFields::PORTAL_STATUSES, true)) {
            throw new SalonBookingRefused('The portal may only set a request to pending, cancelled or expired; the till accepts or declines it.');
        }

        $branch = (string) $model->getAttribute('branch_id');

        if ($branch === '' || ($model->exists && $branch !== (string) $model->getRawOriginal('branch_id'))) {
            throw new SalonBookingRefused('An online booking request belongs to one shop and never moves to another.');
        }

        $kind = $model->getAttribute('kind');
        $kind = $kind instanceof \BackedEnum ? $kind->value : $kind;

        foreach (SalonBookingFields::tillColumns(is_string($kind) ? $kind : null) as $column) {
            $value = $model->getAttribute($column);
            $set = $model->exists ? $model->isDirty($column) : ($value !== null && $value !== '');

            if ($set) {
                throw new SalonBookingRefused("Only the till writes {$column} on an online booking request.");
            }
        }

        if (! $model->exists) {
            return;
        }

        $changed = array_diff(array_keys($model->getDirty()), self::BOOKKEEPING);

        if ($changed !== [] && $model->getRawOriginal('status') !== SalonBookingFields::OPEN_STATUS) {
            throw new SalonBookingRefused('This online booking request has been answered or has ended and can no longer be changed; send a new request instead.');
        }
    }
}
