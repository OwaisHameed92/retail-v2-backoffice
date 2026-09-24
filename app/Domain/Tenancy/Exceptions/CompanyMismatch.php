<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when code tries to write a tenant row for a company other than the current one.
 */
class CompanyMismatch extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Refusing to write tenant model [{$model}] for a company other than the current company.");
    }
}
