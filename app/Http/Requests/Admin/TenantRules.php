<?php

namespace App\Http\Requests\Admin;

use App\Domain\Shared\Country\ContactRules;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validation rules, messages and input clean-up shared by the tenant, branch and register form requests.
 */
final class TenantRules
{
    public const VAT_PATTERN = '/^(GB|XI)(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/';

    public const COMPANY_NUMBER_PATTERN = '/^([A-Z]{2}\d{6}|\d{8})$/';

    public const PHONE_PATTERN = '/^[0-9+()\s-]{7,20}$/';

    /** A UK postcode as typed (the till tidies it): letters, digits and one optional space. */
    public const POSTCODE_PATTERN = '/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/';

    /**
     * @return array<string, mixed>
     */
    public static function company(): array
    {
        // The business ids follow the country profile (Pakistan plan P3): GB's patterns are VAT_PATTERN and
        // COMPANY_NUMBER_PATTERN exactly (pinned by CountryProfileTest); PK adds the STRN.
        $strn = self::taxIdPattern('strn');

        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'vat_number' => ['nullable', 'string', 'regex:'.(self::taxIdPattern('vat_number') ?? self::VAT_PATTERN)],
            ...($strn === null ? [] : ['strn' => ['nullable', 'string', 'regex:'.$strn]]),
            'company_number' => ['nullable', 'string', 'regex:'.(self::taxIdPattern('company_number') ?? self::COMPANY_NUMBER_PATTERN)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'regex:'.ContactRules::phonePattern(self::PHONE_PATTERN)],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'business_type' => ['nullable', Rule::enum(BusinessType::class)],
            'town' => ContactRules::town(['nullable', 'string', 'max:80'], ['address', 'postcode']),
            'postcode' => ContactRules::postcode(['nullable', 'string', 'max:10', 'regex:'.self::POSTCODE_PATTERN]),
            'owner_name' => ['nullable', 'string', 'max:80'],
            'receipt_footer' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'trial_ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Branch rules. `$prefix` is "branch_" on the create-tenant form, "" on branch forms.
     *
     * @return array<string, mixed>
     */
    public static function branch(string $prefix = '', ?string $companyId = null, ?string $ignoreBranchId = null): array
    {
        $code = ['required', 'string', 'regex:'.Branch::CODE_PATTERN];

        if ($companyId !== null) {
            // Includes soft-deleted branches on purpose: a code is never reused.
            $code[] = Rule::unique('branches', 'code')->where('company_id', $companyId)->ignore($ignoreBranchId);
        }

        $rules = [
            $prefix.'code' => $code,
            $prefix.'name' => ['required', 'string', 'max:120'],
            $prefix.'address' => ['nullable', 'string', 'max:500'],
            $prefix.'phone' => ['nullable', 'string', 'regex:'.ContactRules::phonePattern(self::PHONE_PATTERN)],
            $prefix.'vat_number' => ['nullable', 'string', 'regex:'.(self::taxIdPattern('vat_number') ?? self::VAT_PATTERN)],
            $prefix.'town' => ContactRules::town(['nullable', 'string', 'max:80'], [$prefix.'address', $prefix.'postcode']),
            $prefix.'postcode' => ContactRules::postcode(['nullable', 'string', 'max:10', 'regex:'.self::POSTCODE_PATTERN]),
            $prefix.'receipt_footer' => ['nullable', 'string', 'max:200'],
            $prefix.'nation' => ['required', Nation::rule()],
            $prefix.'licensed_hours_json' => ['nullable', 'string', 'json', 'max:4000'],
            $prefix.'is_drs_return_point' => ['boolean'],
            $prefix.'area_m2' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];

        // Phase P10: fields the country profile hides are not read from the request (branchDetails), so not checked.
        return array_diff_key($rules, array_flip(CountryModules::hiddenFields([
            CountryModules::DEPOSIT_RETURN => [$prefix.'is_drs_return_point'],
            CountryModules::ALCOHOL_LICENSING => [$prefix.'licensed_hours_json'],
        ])));
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        $country = app(Country::class);
        // GB keeps its wording; another profile says "Enter the NTN, for example 1234567-8.".
        $id = function (string $column, string $gb) use ($country): string {
            $taxId = $country->taxIdFor($column);

            return $country->is(Country::DEFAULT) || $taxId === null ? $gb : "Enter the {$taxId['label']}, for example {$taxId['example']}.";
        };

        return [
            'vat_number.regex' => $id('vat_number', 'Enter a UK VAT number like GB123456789.'),
            $prefix.'vat_number.regex' => $id('vat_number', 'Enter a UK VAT number like GB123456789.'),
            ...($country->taxIdFor('strn') === null ? [] : ['strn.regex' => $id('strn', '')]),
            'company_number.regex' => $id('company_number', 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.'),
            // Postcode, town and phone follow the profile (Pakistan plan P4); GB keeps these exact messages.
            'phone.regex' => ContactRules::phoneText('Enter a phone number like 0113 496 0000.'),
            'postcode.regex' => ContactRules::postcodeMessage('Enter a UK postcode like LS1 6AB.'),
            $prefix.'postcode.regex' => ContactRules::postcodeMessage('Enter a UK postcode like LS1 6AB.'),
            $prefix.'phone.regex' => ContactRules::phoneText('Enter a phone number like 0113 496 0000.'),
            ...ContactRules::townMessages(),
            ...ContactRules::townMessages($prefix.'town'),
            $prefix.'code.regex' => 'Use 2 to 5 capital letters, for example LDS.',
            $prefix.'code.unique' => 'Another branch of this business already uses this code.',
            $prefix.'licensed_hours_json.json' => 'Licensed hours must be valid JSON, as set on the till.',
            $prefix.'area_m2.decimal' => 'Use up to 2 decimal places.',
        ];
    }

    /**
     * Trim strings, blank → null, upper-case codes and VAT/company numbers, lower-case emails.
     *
     * @param  list<string>  $prefixes
     * @return array<string, mixed>
     */
    public static function clean(FormRequest $request, array $prefixes = ['']): array
    {
        $uk = app(Country::class)->is(Country::DEFAULT);
        $clean = [];

        foreach ($request->all() as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        foreach ($prefixes as $prefix) {
            foreach (['vat_number', 'company_number', 'code'] as $field) {
                $key = $prefix.$field;
                if (isset($clean[$key])) {
                    $clean[$key] = strtoupper((string) preg_replace('/\s+/', '', $clean[$key]));
                }
            }

            $postcodeKey = $prefix.'postcode';
            if (isset($clean[$postcodeKey])) {
                $clean[$postcodeKey] = strtoupper((string) preg_replace('/\s+/', ' ', $clean[$postcodeKey]));
            }

            // A UK VAT number typed without "GB" and a short Companies House number: GB only (an NTN or SECP
            // number is kept as typed).
            $vatKey = $prefix.'vat_number';
            if ($uk && isset($clean[$vatKey]) && preg_match('/^\d{9}(\d{3})?$/', (string) $clean[$vatKey]) === 1) {
                $clean[$vatKey] = 'GB'.$clean[$vatKey];
            }

            $numberKey = $prefix.'company_number';
            if ($uk && isset($clean[$numberKey]) && preg_match('/^\d{1,7}$/', (string) $clean[$numberKey]) === 1) {
                $clean[$numberKey] = str_pad((string) $clean[$numberKey], 8, '0', STR_PAD_LEFT);
            }

            // PK STRN: digits only, however it was grouped ("17-00-1234-567-89").
            $strnKey = $prefix.'strn';
            if (! $uk && isset($clean[$strnKey])) {
                $clean[$strnKey] = (string) preg_replace('/[\s-]+/', '', (string) $clean[$strnKey]);
            }
        }

        foreach (['email', 'owner_email'] as $key) {
            if (isset($clean[$key])) {
                $clean[$key] = strtolower($clean[$key]);
            }
        }

        return $clean;
    }

    public static function companyDetails(FormRequest $request): CompanyDetails
    {
        $trialEndsAt = $request->input('trial_ends_at');

        return new CompanyDetails(
            name: (string) $request->input('name'),
            legalName: self::nullableString($request->input('legal_name')),
            vatNumber: self::nullableString($request->input('vat_number')),
            companyNumber: self::nullableString($request->input('company_number')),
            address: self::nullableString($request->input('address')),
            phone: self::nullableString($request->input('phone')),
            email: self::nullableString($request->input('email')),
            contactName: self::nullableString($request->input('contact_name')),
            notes: self::nullableString($request->input('notes')),
            trialEndsAt: is_string($trialEndsAt) ? Carbon::parse($trialEndsAt, Country::zone())->endOfDay()->utc() : null,
            businessType: BusinessType::tryFrom((string) $request->input('business_type')),
            town: self::nullableString($request->input('town')),
            postcode: self::nullableString($request->input('postcode')),
            ownerName: self::nullableString($request->input('owner_name')),
            receiptFooter: self::nullableString($request->input('receipt_footer')),
            strn: self::nullableString($request->input('strn')),
        );
    }

    /** The profile's pattern for what a business column holds (`vat_number`, `strn`, `company_number`), if any. */
    private static function taxIdPattern(string $column): ?string
    {
        return app(Country::class)->taxIdFor($column)['pattern'] ?? null;
    }

    /**
     * `$stored` is the branch being edited. Where the country profile hides deposit return or alcohol licensing (P10),
     * the forms have no "Deposit return point" or "Licensed hours": an edit keeps the stored values, a new branch gets
     * the defaults (not a return point, no licensed hours), whatever the request carries.
     */
    public static function branchDetails(FormRequest $request, string $prefix = '', ?Branch $stored = null): BranchDetails
    {
        $area = $request->input($prefix.'area_m2');
        $keepDrs = ! CountryModules::on(CountryModules::DEPOSIT_RETURN);
        $keepHours = ! CountryModules::on(CountryModules::ALCOHOL_LICENSING);

        return new BranchDetails(
            code: (string) $request->input($prefix.'code'),
            name: (string) $request->input($prefix.'name'),
            nation: Nation::forShop((string) $request->input($prefix.'nation')),
            address: self::nullableString($request->input($prefix.'address')),
            phone: self::nullableString($request->input($prefix.'phone')),
            vatNumber: self::nullableString($request->input($prefix.'vat_number')),
            licensedHoursJson: $keepHours ? $stored?->licensed_hours_json : self::nullableString($request->input($prefix.'licensed_hours_json')),
            isDrsReturnPoint: $keepDrs ? (bool) $stored?->is_drs_return_point : $request->boolean($prefix.'is_drs_return_point'),
            areaM2: $area === null || $area === '' ? null : (string) $area,
            town: self::nullableString($request->input($prefix.'town')),
            postcode: self::nullableString($request->input($prefix.'postcode')),
            receiptFooter: self::nullableString($request->input($prefix.'receipt_footer')),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
