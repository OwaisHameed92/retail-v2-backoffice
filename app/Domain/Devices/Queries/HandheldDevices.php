<?php

namespace App\Domain\Devices\Queries;

use App\Domain\Devices\Enums\DeviceStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Handhelds for the admin Devices page: one row per signed-in PDA app (a `handheld_tokens` row) with its user and
 * shop, across every business (admin code: the query builder, like HealthSources). Last seen = the token's last
 * use, else its sign-in. Signed-in devices only, unless `signedOut` is asked for. The app sends no version, so
 * `app_version` is empty. Same columns as TillDevices; never the token hash or its last 4.
 */
final class HandheldDevices
{
    public static function query(?DeviceStatus $status): Builder
    {
        $query = DB::table('handheld_tokens')
            ->join('handheld_users', 'handheld_users.id', '=', 'handheld_tokens.handheld_user_id')
            ->join('companies', 'companies.id', '=', 'handheld_tokens.company_id')
            ->join('branches', 'branches.id', '=', 'handheld_users.branch_id')
            ->select([
                DB::raw("'handheld' as kind"),
                'handheld_tokens.id',
                'handheld_tokens.company_id',
                'companies.name as company_name',
                'handheld_users.branch_id',
                'branches.name as branch_name',
                'branches.code as branch_code',
                'handheld_users.name as name',
                DB::raw('null as code'),
                DB::raw('null as is_main_till'),
                DB::raw('null as app_version'),
                DB::raw('coalesce(handheld_tokens.last_used_at, handheld_tokens.created_at) as last_seen_at'),
                DB::raw('null as install_id'),
                'handheld_tokens.device_name as device_name',
                DB::raw('null as key_last4'),
                DB::raw('null as clock_skew_seconds'),
                DB::raw("case when handheld_tokens.revoked_at is null and handheld_users.is_active = 1 then 'signedIn' else 'signedOut' end as status"),
            ]);

        self::whereStatus($query, $status ?? DeviceStatus::SignedIn);

        return $query;
    }

    /** Keep handhelds whose status is `$status` (signed in by default). */
    public static function whereStatus(Builder $query, DeviceStatus $status): void
    {
        match ($status) {
            DeviceStatus::SignedIn => $query->whereNull('handheld_tokens.revoked_at')->where('handheld_users.is_active', true),
            DeviceStatus::SignedOut => $query->where(fn (Builder $q) => $q->whereNotNull('handheld_tokens.revoked_at')->orWhere('handheld_users.is_active', false)),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
