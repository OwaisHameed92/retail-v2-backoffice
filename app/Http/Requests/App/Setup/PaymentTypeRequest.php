<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\Setup\Actions\SavePaymentType;

/** Adding or editing a payment type (module 4.5). Route: `company.can:settings.manage`. */
class PaymentTypeRequest extends CompanyWideWriteRequest
{
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
