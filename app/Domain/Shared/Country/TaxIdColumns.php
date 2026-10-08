<?php

namespace App\Domain\Shared\Country;

use App\Domain\Tenancy\Models\Company;

/**
 * Pak POS pack 2026-10-07: on PK `vat_number` holds the STRN (as the till reads Company / Branch `vatNumber`). Before,
 * P3 kept the STRN in `companies.strn`; that column is no longer read or written on PK, and a value still in it is
 * offered in the form's STRN field (then saved to `vat_number`). GB: always `vat_number` (its `strn` is never set).
 */
final class TaxIdColumns
{
    public static function vatNumber(Company $company): ?string
    {
        $legacy = trim((string) $company->strn);

        return ! app(Country::class)->is(Country::DEFAULT) && $legacy !== '' ? $legacy : $company->vat_number;
    }
}
