<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\TillData\Models\PaymentType;

/** Adding or editing a payment type (module 4.5). Route: `company.can:settings.manage`. */
class PaymentTypeRequest extends CompanyWideWriteRequest
{
    /** Phase P10: where the country profile hides the deposit return scheme, the form has no "Deposit return": keep it. */
    protected function prepareForValidation(): void
    {
        $id = $this->route('paymentType');

        $this->merge(CountryModules::keep(
            [CountryModules::DEPOSIT_RETURN => ['is_drs_refund']],
            fn () => is_string($id) ? PaymentType::query()->find($id) : null,
            ['is_drs_refund' => false],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:40'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];

        foreach (SavePaymentType::FLAGS as $flag) {
            $rules[$flag] = ['boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.required' => 'Enter the name shown on the till\'s pay button.'];
    }
}
