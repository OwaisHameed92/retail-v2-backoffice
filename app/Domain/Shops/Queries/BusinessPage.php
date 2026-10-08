<?php

namespace App\Domain\Shops\Queries;

use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TaxIdColumns;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;

/**
 * Business details on Shops and tills (module 4.7): what every till prints and the licence key carries. The owner
 * edits (`business.manage`, never a one-shop user); everyone else with `shops.view` reads.
 */
final class BusinessPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(CurrentCompany $tenancy): array
    {
        $company = $tenancy->require();

        return [
            'business' => [
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'vat_number' => TaxIdColumns::vatNumber($company),
                'company_number' => $company->company_number,
                'address' => $company->address,
                'town' => $company->town,
                'postcode' => $company->postcode,
                'phone' => $company->phone,
                'email' => $company->email,
                'receipt_footer' => $company->receipt_footer,
                // Pakistan plan P3: the STRN only where the profile has one (GB props unchanged).
            ] + (app(Country::class)->taxIdFor('strn') === null ? [] : ['strn' => $company->strn]),
            'facts' => [
                'status' => $company->status->value,
                'businessType' => $company->business_type?->label(),
                'shops' => Branch::query()->active()->count(),
                'shopsAllowed' => $company->max_branches,
                'customerSince' => LicenceData::date($company->activated_at ?? $company->created_at),
            ],
            'can' => [
                'edit' => $tenancy->can(Ability::BusinessManage) && $tenancy->restrictedBranchId() === null,
            ],
        ];
    }
}
