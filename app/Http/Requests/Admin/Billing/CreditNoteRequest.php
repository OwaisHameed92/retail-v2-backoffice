<?php

namespace App\Http\Requests\Admin\Billing;

use Illuminate\Contracts\Validation\ValidationRule;

class CreditNoteRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['amount' => self::cleanMoney($this->input('amount'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:'.self::MONEY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount to credit.',
            'amount.regex' => self::moneyMessage(),
            'amount.not_regex' => self::aboveZeroMessage(),
            'reason.required' => 'Enter the reason for the credit.',
        ];
    }
}
