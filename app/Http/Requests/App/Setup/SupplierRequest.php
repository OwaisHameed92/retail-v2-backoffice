<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Shared\Country\ContactRules;
use App\Domain\TillData\Enums\SupplierOrderMethod;
use App\Domain\TillData\Enums\SupplierTermsKind;
use Illuminate\Validation\Rule;

/** Adding or editing a supplier (module 4.5). Route: `company.can:suppliers.manage`. */
class SupplierRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'is_active' => ['boolean'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            // Town and postcode follow the country profile (Pakistan plan P4); GB keeps these exact rules.
            'town' => ContactRules::town(['nullable', 'string', 'max:120'], ['address_line1', 'address_line2', 'postcode']),
            'postcode' => ContactRules::postcode(['nullable', 'string', 'max:12']),
            'account_number' => ['nullable', 'string', 'max:60'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'terms_kind' => ['required', Rule::enum(SupplierTermsKind::class)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365', Rule::requiredIf(fn () => $this->input('terms_kind') === SupplierTermsKind::NetDays->value)],
            'default_lead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'minimum_order_value' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'order_method' => ['required', Rule::enum(SupplierOrderMethod::class)],
            'delivery_days' => ['array'],
            'delivery_days.*' => [Rule::in(SaveSupplier::DAYS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the supplier\'s name.',
            'code.regex' => 'Use letters, digits and dashes only.',
            'payment_terms_days.required' => 'Enter how many days you have to pay.',
            ...ContactRules::postcodeFormatMessages(),
            ...ContactRules::townMessages(),
            'minimum_order_value.regex' => 'Enter an amount in pounds, e.g. 50 or 49.99.',
        ];
    }
}
