<?php

namespace App\Domain\Salon\Support;

use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which shops are Salon / Barber shops (salon pack 2026-11-08, W2). The shop type is chosen on the till only (its
 * setup wizard writes `vertical.salon` = true and `setup.business_type` = `Salon`; the wizard tile says
 * "Salon / Barber", the value stays `Salon`), so the portal reads what the tills pushed, plus the business type
 * on the portal's own business record.
 *
 * A shop is a salon when the business type is Salon, or a live (not removed) till setting says so: for the whole
 * business (company scope) or for that shop (branch scope). Everything salon is hidden from every other shop: no
 * salon row, `salon.*` setting or `salon.*` right in its pull (SalonPull), no salon section, right or label on
 * its pages.
 *
 * Reads the tables directly (no tenant scope needed): the pull runs outside a company context.
 */
final class SalonShops
{
    public const BUSINESS_TYPE = 'Salon';

    /** The shop-type flag only the till's wizard writes (SettingSyncPolicy::TILL_WRITES_ONLY_PREFIXES `vertical.`). */
    public const FLAG = 'vertical.salon';

    public static function isSalonBranch(string $companyId, string $branchId): bool
    {
        return self::companyIsSalon($companyId)
            || self::settings($companyId)
                ->where(fn (Builder $q) => $q->where('scope', 'company')->orWhere(fn (Builder $b) => $b->where('scope', 'branch')->where('scope_id', $branchId)))
                ->exists();
    }

    /** Any shop of the business is a salon (the portal pages show the salon sections and words). */
    public static function anySalon(string $companyId): bool
    {
        return self::companyIsSalon($companyId) || self::settings($companyId)->whereIn('scope', ['company', 'branch'])->exists();
    }

    /** A Salon* table (the 41 of the salon pack; SalonClientMedical is never stored). */
    public static function isSalonEntity(string $entity): bool
    {
        return str_starts_with($entity, 'Salon');
    }

    /** A `salon.*` setting key, or the salon shop-type flag. */
    public static function isSalonSetting(string $key): bool
    {
        return str_starts_with(strtolower($key), 'salon.') || strtolower($key) === self::FLAG;
    }

    /** A `salon.*` right. */
    public static function isSalonRight(string $key): bool
    {
        return str_starts_with(strtolower($key), 'salon.');
    }

    private static function companyIsSalon(string $companyId): bool
    {
        return DB::table('companies')->where('id', $companyId)->where('business_type', BusinessType::Salon->value)->exists();
    }

    /** Live till settings of the business that mark a salon. */
    private static function settings(string $companyId): Builder
    {
        return DB::table('till_settings')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $k) => $k->where('setting_key', self::FLAG)->whereRaw('LOWER(value) = ?', ['true']))
                ->orWhere(fn (Builder $k) => $k->where('setting_key', 'setup.business_type')->where('value', self::BUSINESS_TYPE)));
    }
}
