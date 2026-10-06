<?php

namespace App\Domain\Shops\Queries;

use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shops\Data\ShopRequest;
use App\Domain\Shops\Support\TillLicenceView;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillHealth\Queries\CompanyHealth;
use Carbon\CarbonImmutable;

/**
 * One shop on Shops and tills (module 4.7): its details (editable by `shops.manage`), its tills each with the
 * licence read only (status, end date, key's last 4, never the key), health, last licence check and sync times.
 */
final class ShopDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function for(CurrentCompany $tenancy, Branch $branch, CarbonImmutable $now): array
    {
        $company = $tenancy->require();
        $health = CompanyHealth::for($company->id, $now, admin: false);
        $licences = ShopsOverview::liveLicences($company, [$branch->id])->keyBy('register_id');
        $registers = Register::query()->where('branch_id', $branch->id)->orderByDesc('is_active')->orderBy('code')->get();

        $tills = $registers->map(function (Register $register) use ($licences, $health, $now) {
            /** @var Licence|null $licence */
            $licence = $licences->get($register->id);

            return [
                'id' => $register->id,
                'name' => $register->name,
                'code' => $register->code,
                'isMainTill' => $register->is_main_till,
                'isActive' => $register->is_active,
                'licence' => $licence === null ? null : TillLicenceView::of($licence, $now),
                'health' => $health['tills'][$register->id] ?? null,
            ];
        })->values()->all();

        $features = $licences->flatMap(fn (Licence $licence) => $licence->features)->unique(fn (Feature $feature) => $feature->value)
            ->sortBy(fn (Feature $feature) => $feature->label())
            ->map(fn (Feature $feature) => ['value' => $feature->value, 'label' => $feature->label()])->values()->all();
        $ends = $licences->map(fn (Licence $licence) => LicenceData::date($licence->state($now)->endsAt))->filter()->sort()->values();

        return [
            'shop' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'isActive' => $branch->is_active,
                'nation' => $branch->nation->shownLabel(),
                'address' => $branch->address,
                'town' => $branch->town,
                'postcode' => $branch->postcode,
                'phone' => $branch->phone,
                'vatNumber' => $branch->vat_number,
                'receiptFooter' => $branch->receipt_footer,
                'createdAt' => LicenceData::date($branch->created_at),
            ],
            'business' => [
                'name' => $company->name,
                'vatNumber' => $company->vat_number,
                'receiptFooter' => $company->receipt_footer,
            ],
            'licence' => [
                'kind' => $branch->licence_kind->value,
                'tillsAllowed' => $branch->max_registers,
                'tillsActive' => $registers->where('is_active', true)->count(),
                'features' => $features,
                'nextEndsAt' => $ends->first(),
            ],
            'tills' => $tills,
            'health' => $health['branches'][$branch->id] ?? null,
            'thresholds' => $health['thresholds'],
            'requests' => ShopRequests::recent($branch->id),
            'requestOptions' => [
                'shops' => [['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code]],
                'maxTills' => ShopRequest::MAX_TILLS,
                'canAskForShop' => false,
            ],
            'can' => [
                'edit' => $tenancy->can(Ability::ShopsManage) && $branch->is_active,
                'ask' => $tenancy->can(Ability::ShopsManage) && $branch->is_active,
            ],
        ];
    }
}
