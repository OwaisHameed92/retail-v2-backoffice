<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Plans\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * "Change plan" on the tenant Billing tab (owner 2026-10-07): the preview (GET, no writes) and the confirm (POST) take
 * the same fields: the plan (active plans only) and the setup fee the admin typed (net; empty = the suggested amount,
 * 0 = waive it). `billing.manage` (BillingRequest).
 */
class ChangePlanRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $fee = $this->input('setup_fee');

        $this->merge(['setup_fee' => $fee === null || $fee === '' ? null : self::cleanMoney($fee)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'setup_fee' => ['nullable', 'string', 'regex:'.self::moneyPattern()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Choose the plan to move the business to.',
            'plan_id.exists' => 'Choose an active plan.',
            'setup_fee.regex' => self::moneyMessage(),
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->findOrFail((string) $this->validated('plan_id'));
    }

    public function setupFee(): ?string
    {
        $fee = $this->validated('setup_fee');

        return is_string($fee) ? $fee : null;
    }
}
