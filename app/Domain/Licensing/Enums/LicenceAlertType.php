<?php

namespace App\Domain\Licensing\Enums;

/**
 * Why the licence API raised an admin alert (module 1.5). camelCase values.
 */
enum LicenceAlertType: string
{
    /** A second PC tried to activate a key that is bound to another PC. */
    case SameKeyTwoDevices = 'sameKeyTwoDevices';

    /** An install that is not the bound one validated the licence (contract §17.15.2). */
    case DeviceMismatch = 'deviceMismatch';

    /** The PC that was bound before a "Reissue key" still uses the old key. */
    case ReissuedKeyUsed = 'reissuedKeyUsed';

    /** Module 2.1: the till's own ids are already mapped to another business or branch (id_map). */
    case TillIdsConflict = 'tillIdsConflict';

    /** Module 2.7 (Till health): the till has been silent for too long during trading hours. */
    case TillOffline = 'tillOffline';

    /** Module 2.7: the shop's sync keeps failing (a rejected row, a refused key). On the syncing till's licence. */
    case SyncFailing = 'syncFailing';

    /** Module 2.7: the main till is online but its sync stopped, or its queue keeps growing. */
    case SyncStalled = 'syncStalled';

    /** Module 2.7: the till runs an app version below the minimum. */
    case AppVersionOutdated = 'appVersionOutdated';

    /** Module 2.7: the till's clock is off by more than the allowed skew. */
    case ClockSkew = 'clockSkew';

    public function label(): string
    {
        return match ($this) {
            self::SameKeyTwoDevices => 'Same key on two PCs',
            self::DeviceMismatch => 'Check-in from another PC',
            self::ReissuedKeyUsed => 'Old key still in use',
            self::TillIdsConflict => 'Till data belongs elsewhere',
            self::TillOffline => 'Till offline',
            self::SyncFailing => 'Sync failing',
            self::SyncStalled => 'Sync stalled',
            self::AppVersionOutdated => 'Old app version',
            self::ClockSkew => 'Till clock is wrong',
        };
    }

    /** Raised and cleared by `till-health:refresh` (module 2.7), not by the licence API: it clears itself. */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::TillOffline, self::SyncFailing, self::SyncStalled, self::AppVersionOutdated, self::ClockSkew], true);
    }

    /** What staff should do about it. */
    public function help(): string
    {
        return match ($this) {
            self::SameKeyTwoDevices => 'Another PC tried to activate this key. If the customer moved to a new PC, use "Release"; if not, the key may have been shared: reissue it.',
            self::DeviceMismatch => 'A PC that is not the bound one is still checking in with this licence. It gets no new token and locks when it cannot validate for 14 days.',
            self::ReissuedKeyUsed => 'The till that had this licence still uses the key that was replaced. Give the owner the new key.',
            self::TillIdsConflict => 'The PC holds data of another business or branch, so the key was refused. Check the key was given to the right shop; a PC restored from another shop\'s backup needs that shop\'s key.',
            self::TillOffline => 'The till has not been in touch during trading hours. Ask the shop whether the PC is on and online. This alert clears itself when the till is back.',
            self::SyncFailing => 'The portal keeps refusing what the main till sends, so the shop\'s data is not reaching the dashboard. Check the last error on the Till health page. Clears itself after a clean push.',
            self::SyncStalled => 'The main till is online but has stopped syncing, or its waiting rows keep growing. Check cloud sync is switched on and the sync key is current. Clears itself when sync resumes.',
            self::AppVersionOutdated => 'The till runs an SSPOS version older than the minimum we support. Ask the shop to update. Clears itself when the till reports a newer version.',
            self::ClockSkew => 'The till\'s clock differs from ours by more than the allowed skew, which can lock the till or put sales on the wrong day. Ask the shop to set Windows time to automatic. Clears itself when the clock is right.',
        };
    }
}
