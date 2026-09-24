<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when tenant-owned data is touched without a current company. Tenancy fails closed: it never
 * falls back to "all companies".
 */
class MissingCurrentCompany extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("Cannot query tenant model [{$model}] without a current company. Set one with CurrentCompany::set()/runAs(), or use withoutCompanyScope() in admin/sync code.");
    }

    public static function forCreate(string $model): self
    {
        return new self("Cannot create tenant model [{$model}] without a current company or an explicit company_id.");
    }
}
