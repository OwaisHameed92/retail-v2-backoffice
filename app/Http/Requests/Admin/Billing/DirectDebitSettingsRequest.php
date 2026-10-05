<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Data\DirectDebitSettingsInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class DirectDebitSettingsRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $override = $this->input('setup_fee_override');

        $this->merge([
            'setup_fee_override' => $override === null || (is_string($override) && trim($override) === '') ? null : self::cleanMoney($override),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
            'setup_fee_override' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
            // Kept for old clients: the setup fee is always paid by hand (owner rule 2026-10-05), whatever is sent.
            'setup_fee_method' => ['sometimes', 'nullable', Rule::enum(SetupFeeMethod::class)],
            'setup_fee_instalments' => ['required', 'integer', 'min:1', 'max:'.(int) config('billing.direct_debit.max_instalments', 12)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'setup_fee_override.regex' => self::MONEY_MESSAGE,
            'setup_fee_instalments.max' => 'Split the setup fee into at most :max payments.',
        ];
    }

    public function toInput(): DirectDebitSettingsInput
    {
        $override = $this->validated('setup_fee_override');

        return new DirectDebitSettingsInput(
            mode: BillingMode::from((string) $this->validated('billing_mode')),
            setupFeeOverride: is_string($override) ? $override : null,
            setupFeeMethod: SetupFeeMethod::Manual,
            instalments: (int) $this->validated('setup_fee_instalments'),
        );
    }
}
