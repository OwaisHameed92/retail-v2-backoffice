<?php

namespace App\Domain\Licensing\Signing\Exceptions;

use RuntimeException;

/**
 * The active key has no signer certificate and `licence.allow_uncertified` is off: a till would refuse the
 * token. Send the handover (`licence:keys:handover`) and import the reply (`licence:keys:import-cert`).
 */
final class UncertifiedSigningKey extends RuntimeException
{
    public function __construct(string $kid)
    {
        parent::__construct("Licence signing key {$kid} has no signer certificate. Run \"php artisan licence:keys:handover\", send it to the SSPOS owner and import the reply with \"php artisan licence:keys:import-cert\".");
    }
}
