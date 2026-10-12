<?php

namespace App\Http\Requests\Partner;

use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Country\ContactRules;
use App\Domain\Tenancy\Data\NewTenant;
use App\Http\Requests\Admin\Billing\BillingRequest;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Validation\Rule;

/**
 * Validation of the partner's tenant request body (docs/partner-api.md `PUT …/tenant-requests/{externalId}`): the
 * admin new-business form's rules (TenantRules, ContactRules: the country profile's postcode, town, phone and tax id
 * rules and messages), keyed by the API's camelCase paths, and the same tidying (TenantRules::cleanValues).
 */
final class TenantRequestRules
{
    public const MAX_SHOPS = Lead::MAX_SHOPS;

    public const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,64}$/';

    /** API member → the admin form's field it is checked like. */
    private const BUSINESS = [
        'name' => 'name',
        'legalName' => 'legal_name',
        'companyNumber' => 'company_number',
        'vatNumber' => 'vat_number',
        'phone' => 'phone',
        'email' => 'email',
    ];

    private const SHOP = ['name' => 'name', 'address' => 'address', 'town' => 'town', 'postcode' => 'postcode'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $company = TenantRules::company();
        $branch = TenantRules::branch('shops.*.');
        $rules = [
            'seller' => ['required', 'array'],
            'seller.name' => ['required', 'string', 'max:120'],
            'seller.email' => ['required', 'string', 'email', 'max:255'],
            'business' => ['required', 'array'],
            'owner' => ['required', 'array'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'string', 'email', 'max:255'],
            'owner.phone' => $company['phone'],
            'shops' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_SHOPS],
            'shops.*' => ['array'],
            'shops.*.tills' => ['required', 'integer', 'min:1', 'max:'.NewTenant::MAX_TILLS],
            'plan' => ['required', 'string', 'max:80', Rule::exists('plans', 'code')->where('is_active', true)->whereNull('deleted_at')],
            'setupFeePerTill' => ['nullable', 'string', 'regex:'.BillingRequest::moneyPattern()],
            'vatApplies' => ['nullable', 'boolean'],
            'salePrice' => ['nullable', 'string', 'regex:'.BillingRequest::moneyPattern()],
            'signedOn' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (self::BUSINESS as $member => $field) {
            $rules['business.'.$member] = $company[$field];
        }

        foreach (self::SHOP as $member => $field) {
            $rules['shops.*.'.$member] = $branch['shops.*.'.$field];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $tenant = TenantRules::messages();
        $shop = TenantRules::messages('shops.*.');

        return [
            'business.vatNumber.regex' => $tenant['vat_number.regex'],
            'business.companyNumber.regex' => $tenant['company_number.regex'],
            'business.phone.regex' => $tenant['phone.regex'],
            'owner.phone.regex' => $tenant['phone.regex'],
            'shops.*.postcode.regex' => $shop['shops.*.postcode.regex'],
            ...ContactRules::townMessages('shops.*.town'),
            'plan.exists' => 'Use the code of an active plan (GET /api/partner/v1/plans).',
            'setupFeePerTill.regex' => BillingRequest::moneyMessage(),
            'salePrice.regex' => BillingRequest::moneyMessage(),
            'shops.max' => 'Send up to '.self::MAX_SHOPS.' shops.',
            'shops.*.tills.max' => 'Up to '.NewTenant::MAX_TILLS.' tills per shop.',
        ];
    }

    /**
     * The body tidied like the admin form (upper-case tax ids and postcodes, "GB" added to a 9-digit UK VAT number,
     * lower-case emails, blank strings → null) and money as plain strings. Unknown members are kept for validation
     * to ignore.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function clean(array $body): array
    {
        foreach (['seller', 'business', 'owner'] as $part) {
            if (is_array($body[$part] ?? null)) {
                $body[$part] = self::cleanPart($body[$part], $part === 'business' ? self::BUSINESS : ['name' => 'name', 'email' => 'email', 'phone' => 'phone']);
            }
        }

        if (is_array($body['shops'] ?? null)) {
            $body['shops'] = array_map(fn ($shop) => is_array($shop) ? self::cleanPart($shop, self::SHOP) : $shop, $body['shops']);
        }

        foreach (['setupFeePerTill', 'salePrice'] as $money) {
            if (array_key_exists($money, $body)) {
                $value = BillingRequest::cleanMoney($body[$money]);
                $body[$money] = $value === '' ? null : $value;
            }
        }

        if (is_string($body['notes'] ?? null)) {
            $body['notes'] = trim($body['notes']) === '' ? null : trim($body['notes']);
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $part
     * @param  array<string, string>  $map
     * @return array<array-key, mixed>
     */
    private static function cleanPart(array $part, array $map): array
    {
        $values = [];

        foreach ($map as $member => $field) {
            if (array_key_exists($member, $part)) {
                $values[$field] = $part[$member];
            }
        }

        $clean = TenantRules::cleanValues($values);

        foreach ($map as $member => $field) {
            if (array_key_exists($field, $clean)) {
                $part[$member] = $clean[$field];
            }
        }

        return $part;
    }
}
