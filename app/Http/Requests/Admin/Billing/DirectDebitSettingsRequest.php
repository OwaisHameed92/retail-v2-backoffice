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
        $blank = fn (mixed $value) => $value === null || (is_string($value) && trim($value) === '');

        $this->merge([
            'setup_fee_override' => $blank($override) ? null : self::cleanMoney($override),
        ]);

        if ($this->has('till_setup_fee_override')) {
            $tillFee = $this->input('till_setup_fee_override');
            $this->merge(['till_setup_fee_override' => $blank($tillFee) ? null : self::cleanMoney($tillFee)]);
        }

        if ($this->has('setup_fee_deposit')) {
            $deposit = $this->input('setup_fee_deposit');
            $this->merge(['setup_fee_deposit' => $blank($deposit) ? null : self::cleanMoney($deposit)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
            'setup_fee_override' => ['nullable', 'string', 'regex:'.self::moneyPattern()],
            // Kept for old clients: the setup fee is always paid by hand (owner rule 2026-10-05), whatever is sent.
            'setup_fee_method' => ['sometimes', 'nullable', Rule::enum(SetupFeeMethod::class)],
            // P11: the business's own setup fee for each till added later (per-till plans); blank = the plan's.
            'till_setup_fee_override' => ['sometimes', 'nullable', 'string', 'regex:'.self::moneyPattern()],
            'setup_fee_instalments' => ['required', 'integer', 'min:1', 'max:'.(int) config('billing.direct_debit.max_instalments', 12)],
            // Owner 2026-11-05: "Setup fee: By Direct Debit" (a per-business exception); absent = keep the current choice.
            'setup_fee_by_direct_debit' => ['sometimes', 'boolean'],
            // Owner 2026-11-11: the first instalment (deposit, net); blank = equal instalments; absent = keep it.
            // UpdateDirectDebitSettings checks it against the fee and the instalments (SetupFeeDeposit::problem).
            'setup_fee_deposit' => ['sometimes', 'nullable', 'string', 'regex:'.self::moneyPattern()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'setup_fee_override.regex' => self::moneyMessage(),
            'till_setup_fee_override.regex' => self::moneyMessage(),
            'setup_fee_deposit.regex' => self::moneyMessage(),
            'setup_fee_instalments.max' => 'Split the setup fee into at most :max payments.',
        ];
    }

    public function toInput(): DirectDebitSettingsInput
    {
        $override = $this->validated('setup_fee_override');
        $tillFee = $this->validated('till_setup_fee_override');
        $deposit = $this->validated('setup_fee_deposit');

        return new DirectDebitSettingsInput(
            mode: BillingMode::from((string) $this->validated('billing_mode')),
            setupFeeOverride: is_string($override) ? $override : null,
            setupFeeMethod: SetupFeeMethod::Manual,
            instalments: (int) $this->validated('setup_fee_instalments'),
            tillFeeGiven: $this->has('till_setup_fee_override'),
            tillSetupFeeOverride: is_string($tillFee) ? $tillFee : null,
            setupFeeByDirectDebit: $this->has('setup_fee_by_direct_debit') ? $this->boolean('setup_fee_by_direct_debit') : null,
            depositGiven: $this->has('setup_fee_deposit'),
            setupFeeDeposit: is_string($deposit) ? $deposit : null,
        );
    }
}
