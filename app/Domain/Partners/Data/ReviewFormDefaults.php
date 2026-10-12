<?php

namespace App\Domain\Partners\Data;

use App\Domain\Leads\Support\BranchCodeSuggester;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Tenancy\Enums\Nation;
use Carbon\CarbonImmutable;

/**
 * The review form of a partner request, pre-filled from the partner's body in the new-business wizard's field names
 * (TenantRules, LicenceFormRules), so the admin edits exactly what Approve will create:
 *
 * - business details from `business`, the address from the first shop, the contact and owner login from `owner`;
 * - one shop per partner shop, with a suggested branch code (2–5 letters, unique) and its tills;
 * - the plan by its code (the portal default if the code is no longer active), the setup fee per till and VAT;
 * - internal notes saying where the deal came from (partner, deal id, seller, sale price, date, owner's phone, notes).
 */
final class ReviewFormDefaults
{
    /**
     * @return array<string, mixed>
     */
    public static function for(PartnerTenantRequest $request): array
    {
        $payload = $request->payload;
        $business = is_array($payload['business'] ?? null) ? $payload['business'] : [];
        $owner = is_array($payload['owner'] ?? null) ? $payload['owner'] : [];
        $shops = array_values(array_filter(is_array($payload['shops'] ?? null) ? $payload['shops'] : [], 'is_array'));
        $first = $shops[0] ?? [];
        $plan = Plan::query()->where('code', (string) ($payload['plan'] ?? ''))->where('is_active', true)->first() ?? DefaultPlan::portal();
        $text = fn (array $values, string $key): string => is_string($values[$key] ?? null) ? $values[$key] : '';
        $taken = [];

        return [
            'name' => $text($business, 'name'),
            'legal_name' => $text($business, 'legalName'),
            'vat_number' => $text($business, 'vatNumber'),
            'company_number' => $text($business, 'companyNumber'),
            'email' => $text($business, 'email'),
            'phone' => $text($business, 'phone'),
            'contact_name' => $text($owner, 'name'),
            'address' => $text($first, 'address'),
            'business_type' => '',
            'town' => $text($first, 'town'),
            'postcode' => $text($first, 'postcode'),
            'receipt_footer' => '',
            'status' => 'trial',
            'trial_ends_at' => '',
            'notes' => self::notes($request),
            'owner_name' => $text($owner, 'name'),
            'owner_email' => $text($owner, 'email'),
            'plan_id' => $plan->id ?? '',
            'setup_fee_per_till' => is_string($payload['setupFeePerTill'] ?? null) ? $payload['setupFeePerTill'] : '',
            'vat_applies' => ($payload['vatApplies'] ?? true) !== false,
            'shops' => array_map(function (array $shop) use ($text, &$taken) {
                $code = BranchCodeSuggester::suggest($text($shop, 'name'), $taken);
                $taken[] = $code;
                $tills = max(1, (int) ($shop['tills'] ?? 1));

                return [
                    'code' => $code,
                    'name' => $text($shop, 'name'),
                    'nation' => Nation::forShop(Nation::England->value)->value,
                    'address' => $text($shop, 'address'),
                    'phone' => '',
                    'vat_number' => '',
                    'area_m2' => '',
                    'is_drs_return_point' => false,
                    'licensed_hours_json' => '',
                    'town' => $text($shop, 'town'),
                    'postcode' => $text($shop, 'postcode'),
                    'receipt_footer' => '',
                    'tills' => $tills,
                    'tills_allowed' => $tills,
                ];
            }, $shops),
        ];
    }

    /** Where the deal came from, for the business's internal notes (staff only). */
    public static function notes(PartnerTenantRequest $request): string
    {
        $payload = $request->payload;
        $owner = is_array($payload['owner'] ?? null) ? $payload['owner'] : [];
        $partner = $request->partner->name ?? 'a partner';
        $lines = ["From {$partner} deal {$request->external_id}, sold by {$request->seller_name} ({$request->seller_email})."];

        $sale = [];
        if (is_string($payload['salePrice'] ?? null)) {
            $sale[] = 'sale price '.MoneyFormat::format($payload['salePrice']);
        }
        if (is_string($payload['signedOn'] ?? null)) {
            $sale[] = 'signed on '.MailFormat::date(CarbonImmutable::parse($payload['signedOn'], Country::zone()));
        }
        if ($sale !== []) {
            $lines[] = ucfirst(implode(', ', $sale)).'.';
        }

        if (is_string($owner['phone'] ?? null) && $owner['phone'] !== '') {
            $lines[] = "Owner's phone: {$owner['phone']}.";
        }

        if (is_string($payload['notes'] ?? null) && $payload['notes'] !== '') {
            $lines[] = 'Partner notes: '.$payload['notes'];
        }

        return mb_substr(implode(' ', $lines), 0, 5000);
    }
}
