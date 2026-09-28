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

    public function label(): string
    {
        return match ($this) {
            self::SameKeyTwoDevices => 'Same key on two PCs',
            self::DeviceMismatch => 'Check-in from another PC',
            self::ReissuedKeyUsed => 'Old key still in use',
        };
    }

    /** What staff should do about it. */
    public function help(): string
    {
        return match ($this) {
            self::SameKeyTwoDevices => 'Another PC tried to activate this key. If the customer moved to a new PC, use "Release"; if not, the key may have been shared: reissue it.',
            self::DeviceMismatch => 'A PC that is not the bound one is still checking in with this licence. It gets no new token and locks when it cannot validate for 14 days.',
            self::ReissuedKeyUsed => 'The till that had this licence still uses the key that was replaced. Give the owner the new key.',
        };
    }
}
