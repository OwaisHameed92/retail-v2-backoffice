<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\DispensingRecord;
use App\Domain\TillData\Models\MedicineClassification;
use App\Domain\TillData\Models\Parcel;
use App\Domain\TillData\Models\ParcelCarrier;

/**
 * Which service modules a business uses (module 5.10). No plan feature covers them (the 11 till features have no
 * pharmacy or parcels), so the business type decides, and a till that already recorded one wins:
 * - Pharmacy: business type Pharmacy, or any dispensing record or medicine class.
 * - Parcels: every business type except salons, clothing and footwear shops and cash and carries, or any carrier or
 *   parcel on a till.
 * The menu hides what a business does not use (HandleInertiaRequests) and the pages answer 404.
 */
final class ServiceModules
{
    /** Business types that do not take parcels unless a till says otherwise. */
    public const NO_PARCELS = [BusinessType::Salon, BusinessType::ClothingFootwear, BusinessType::CashAndCarry];

    public static function pharmacy(Company $company): bool
    {
        return $company->business_type === BusinessType::Pharmacy
            || DispensingRecord::withoutCompanyScope()->where('company_id', $company->id)->exists()
            || MedicineClassification::withoutCompanyScope()->where('company_id', $company->id)->exists();
    }

    public static function parcels(Company $company): bool
    {
        return ! in_array($company->business_type, self::NO_PARCELS, true)
            || ParcelCarrier::withoutCompanyScope()->where('company_id', $company->id)->exists()
            || Parcel::withoutCompanyScope()->where('company_id', $company->id)->exists();
    }

    /**
     * The role's abilities less the modules this business does not use (for the menu).
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function visibleAbilities(?Company $company, array $abilities): array
    {
        if ($company === null) {
            return $abilities;
        }

        return array_values(array_filter($abilities, fn (string $ability) => match ($ability) {
            Ability::PharmacyView->value => self::pharmacy($company),
            Ability::ParcelsView->value => self::parcels($company),
            default => true,
        }));
    }
}
