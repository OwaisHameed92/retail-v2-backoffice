<?php

namespace App\Domain\Licensing\Signing\Exceptions;

use RuntimeException;

/**
 * Refuses to create a first key when one is already active (use rotate, or generate --force).
 */
final class ActiveSigningKeyExists extends RuntimeException
{
    public function __construct(string $kid)
    {
        parent::__construct("An active licence signing key already exists ({$kid}). Use licence:keys:rotate, or --force.");
    }
}
