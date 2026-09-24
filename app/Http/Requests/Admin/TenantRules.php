<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
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

    /**
     * @return array<string, mixed>
     */
    public static function company(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'vat_number' => ['nullable', 'string', 'regex:'.self::VAT_PATTERN],
            'company_number' => ['nullable', 'string', 'regex:'.self::COMPANY_NUMBER_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'regex:'.self::PHONE_PATTERN],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:120'],
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

        return [
            $prefix.'code' => $code,
            $prefix.'name' => ['required', 'string', 'max:120'],
            $prefix.'address' => ['nullable', 'string', 'max:500'],
            $prefix.'phone' => ['nullable', 'string', 'regex:'.self::PHONE_PATTERN],
            $prefix.'vat_number' => ['nullable', 'string', 'regex:'.self::VAT_PATTERN],
            $prefix.'nation' => ['required', Rule::enum(Nation::class)],
            $prefix.'licensed_hours_json' => ['nullable', 'string', 'json', 'max:4000'],
            $prefix.'is_drs_return_point' => ['boolean'],
            $prefix.'area_m2' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        return [
            'vat_number.regex' => 'Enter a UK VAT number like GB123456789.',
            $prefix.'vat_number.regex' => 'Enter a UK VAT number like GB123456789.',
            'company_number.regex' => 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.',
            'phone.regex' => 'Enter a phone number like 0113 496 0000.',
            $prefix.'phone.regex' => 'Enter a phone number like 0113 496 0000.',
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

            $vatKey = $prefix.'vat_number';
            if (isset($clean[$vatKey]) && preg_match('/^\d{9}(\d{3})?$/', (string) $clean[$vatKey]) === 1) {
                $clean[$vatKey] = 'GB'.$clean[$vatKey];
            }

            $numberKey = $prefix.'company_number';
            if (isset($clean[$numberKey]) && preg_match('/^\d{1,7}$/', (string) $clean[$numberKey]) === 1) {
                $clean[$numberKey] = str_pad((string) $clean[$numberKey], 8, '0', STR_PAD_LEFT);
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
            trialEndsAt: is_string($trialEndsAt) ? Carbon::parse($trialEndsAt, 'Europe/London')->endOfDay()->utc() : null,
        );
    }

    public static function branchDetails(FormRequest $request, string $prefix = ''): BranchDetails
    {
        $area = $request->input($prefix.'area_m2');

        return new BranchDetails(
            code: (string) $request->input($prefix.'code'),
            name: (string) $request->input($prefix.'name'),
            nation: Nation::from((string) $request->input($prefix.'nation')),
            address: self::nullableString($request->input($prefix.'address')),
            phone: self::nullableString($request->input($prefix.'phone')),
            vatNumber: self::nullableString($request->input($prefix.'vat_number')),
            licensedHoursJson: self::nullableString($request->input($prefix.'licensed_hours_json')),
            isDrsReturnPoint: $request->boolean($prefix.'is_drs_return_point'),
            areaM2: $area === null || $area === '' ? null : (string) $area,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
