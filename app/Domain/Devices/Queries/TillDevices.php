<?php

namespace App\Domain\Devices\Queries;

use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Queries\LicenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Tills for the admin Devices page: one row per licence (one licence = one till), across every business, from
 * LicenceQuery::admin() (the documented `withoutCompanyScope()` read) plus the till's stored health row for the
 * last sync contact. Live licences only, unless `revoked` is asked for. Columns match HandheldDevices so the two
 * can be listed together; never a key hash or token.
 */
final class TillDevices
{
    /** The device columns, in union order. */
    public const COLUMNS = [
        'kind', 'id', 'company_id', 'company_name', 'branch_id', 'branch_name', 'branch_code', 'name', 'code',
        'is_main_till', 'app_version', 'last_seen_at', 'install_id', 'device_name', 'key_last4', 'clock_skew_seconds',
        'status',
    ];

    /**
     * @param  list<string>|null  $versions  Only tills on one of these EPOS versions (null: any).
     */
    public static function query(?DeviceStatus $status, CarbonImmutable $now, ?array $versions = null): QueryBuilder
    {
        $query = LicenceQuery::admin()
            // On the till's unique register_id (indexed); only while the health row is about this licence.
            ->leftJoin('till_health', function (JoinClause $join): void {
                $join->on('till_health.register_id', '=', 'licences.register_id')->whereColumn('till_health.licence_id', 'licences.id');
            })
            ->select([
                DB::raw("'till' as kind"),
                'licences.id',
                'licences.company_id',
                'companies.name as company_name',
                'licences.branch_id',
                'branches.name as branch_name',
                'branches.code as branch_code',
                'registers.name as name',
                'registers.code as code',
                'registers.is_main_till as is_main_till',
                'licences.last_app_version as app_version',
                DB::raw(self::lastSeenSql().' as last_seen_at'),
                'licences.device_id as install_id',
                'licences.device_name as device_name',
                'licences.key_last4 as key_last4',
                'licences.till_clock_skew_seconds as clock_skew_seconds',
                // Worked out per row from the licence (DeviceStatus::forTill).
                DB::raw('null as status'),
            ]);

        if ($status === null || ! $status->isPast()) {
            $query->live();
        }

        if ($status !== null) {
            self::whereStatus($query, $status, $now);
        }

        if ($versions !== null) {
            $versions === [] ? $query->whereRaw('1 = 0') : $query->whereIn('licences.last_app_version', $versions);
        }

        return $query->toBase();
    }

    /**
     * The latest of the licence check-in and the shop's last sync contact (the till health row, main till only).
     * Both columns hold UTC "Y-m-d H:i:s", so they compare the same on SQLite and MySQL.
     */
    public static function lastSeenSql(): string
    {
        return 'case when till_health.last_seen_at is null then licences.last_check_in_at '
            .'when licences.last_check_in_at is null or till_health.last_seen_at > licences.last_check_in_at then till_health.last_seen_at '
            .'else licences.last_check_in_at end';
    }

    /**
     * Keep tills whose DeviceStatus is `$status` (the SQL twin of DeviceStatus::forTill). Needs LicenceQuery::admin().
     *
     * @param  Builder<Licence>  $query
     */
    public static function whereStatus(Builder $query, DeviceStatus $status, CarbonImmutable $now): void
    {
        $expiring = DeviceStatus::expiringBefore($now);

        match ($status) {
            DeviceStatus::Revoked => LicenceQuery::whereEffectiveStatus($query, LicenceStatus::Revoked, $now),
            DeviceStatus::Suspended => LicenceQuery::whereEffectiveStatus($query, LicenceStatus::Suspended, $now),
            DeviceStatus::Expired => LicenceQuery::whereEffectiveStatus($query, LicenceStatus::Expired, $now),
            DeviceStatus::NotActivated => LicenceQuery::whereEffectiveStatus($query, LicenceStatus::Issued, $now),
            DeviceStatus::Released => self::trading($query, $now)->whereNull('licences.device_id'),
            DeviceStatus::Expiring => $query->whereNotNull('licences.device_id')->where(function (Builder $q) use ($now, $expiring): void {
                $q->where(fn (Builder $w) => LicenceQuery::whereEffectiveStatus($w, LicenceStatus::Grace, $now))
                    ->orWhere(fn (Builder $w) => self::paidOrTrial($w, $now)->where('licences.ends_at', '<=', $expiring));
            }),
            DeviceStatus::Active => self::paidOrTrial($query->whereNotNull('licences.device_id'), $now)->where('licences.ends_at', '>', $expiring),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Trial, active or grace: the till may trade.
     *
     * @param  Builder<Licence>  $query
     * @return Builder<Licence>
     */
    private static function trading(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where(function (Builder $q) use ($now): void {
            $q->where(fn (Builder $w) => LicenceQuery::whereEffectiveStatus($w, LicenceStatus::Grace, $now))
                ->orWhere(fn (Builder $w) => self::paidOrTrial($w, $now));
        });
    }

    /**
     * @param  Builder<Licence>  $query
     * @return Builder<Licence>
     */
    private static function paidOrTrial(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where(function (Builder $q) use ($now): void {
            $q->where(fn (Builder $w) => LicenceQuery::whereEffectiveStatus($w, LicenceStatus::Trial, $now))
                ->orWhere(fn (Builder $w) => LicenceQuery::whereEffectiveStatus($w, LicenceStatus::Active, $now));
        });
    }
}
