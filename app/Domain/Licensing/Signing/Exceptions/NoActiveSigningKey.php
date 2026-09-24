<?php

namespace App\Domain\Licensing\Signing\Exceptions;

use RuntimeException;

/**
 * No active licence signing key exists yet. Run `php artisan licence:keys:generate` once per environment.
 */
final class NoActiveSigningKey extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No active licence signing key. Run "php artisan licence:keys:generate".');
    }
}
