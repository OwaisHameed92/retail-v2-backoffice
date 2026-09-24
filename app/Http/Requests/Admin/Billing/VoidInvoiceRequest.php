<?php

namespace App\Http\Requests\Admin\Billing;

use Illuminate\Contracts\Validation\ValidationRule;

class VoidInvoiceRequest extends BillingRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'redraft' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Enter why the invoice is void.'];
    }
}
