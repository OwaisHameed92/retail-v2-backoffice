<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Sharding groundwork (docs/scaling.md): which database connection holds a company's data —
 * `companies.data_connection`, else the default connection. A name that is not configured in
 * config/database.php falls back to the default, so a stale value never breaks a request.
 *
 * Nothing routes through this yet: every model still uses the default connection. When a group of tenants moves
 * to another server, tenant models and jobs will ask this class for their connection.
 */
final class CompanyConnection
{
    public static function for(Company|string $company): string
    {
        $name = $company instanceof Company
            ? $company->data_connection
            : DB::table('companies')->where('id', $company)->value('data_connection');

        return is_string($name) && $name !== '' && is_array(config("database.connections.{$name}"))
            ? $name
            : self::default();
    }

    public static function default(): string
    {
        return (string) config('database.default');
    }
}
