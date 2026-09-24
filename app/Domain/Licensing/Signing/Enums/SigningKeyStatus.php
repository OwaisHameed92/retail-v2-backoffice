<?php

namespace App\Domain\Licensing\Signing\Enums;

enum SigningKeyStatus: string
{
    /** Signs new tokens. Exactly one key is active. */
    case Active = 'active';

    /** No longer signs; tokens it signed still verify until retired_at + keep days. */
    case Retired = 'retired';

    /** Retired longer than the keep period: no longer verifies, left out of the JWKS, removed by prune. */
    case Expired = 'expired';
}
