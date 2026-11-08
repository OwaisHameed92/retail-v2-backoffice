<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Shared\Country\CountryModules;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillSetting;

/**
 * Whether one shop is a pharmacy, for the dm+d release server (W4; PORTAL-CHANGES-pharmacy.md item 12: "answer only
 * branches whose `setup.business_type` is `Pharmacy`"). The same signals as VerticalSettings::isPharmacy() (W1),
 * narrowed to the shop: the business type is Pharmacy, or a till sent `setup.business_type` = Pharmacy /
 * `vertical.pharmacy` = true for the business or for this shop (not for another shop of the business). Never where
 * the country profile hides the pharmacy module.
 */
final class PharmacyBranch
{
    public static function is(Company $company, Branch $branch): bool
    {
        if (! CountryModules::on(CountryModules::PHARMACY) || $branch->company_id !== $company->id) {
            return false;
        }

        if ($company->business_type === BusinessType::Pharmacy) {
            return true;
        }

        return TillSetting::withoutCompanyScope()
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->where('scope', 'company')
                ->orWhere(fn ($s) => $s->where('scope', 'branch')->where('scope_id', $branch->id)))
            ->where(fn ($q) => $q->where(fn ($k) => $k->where('setting_key', 'vertical.pharmacy')->where('value', 'true'))
                ->orWhere(fn ($k) => $k->where('setting_key', 'setup.business_type')->where('value', 'Pharmacy')))
            ->exists();
    }
}
