<?php

namespace App\Http\Requests\Admin\Billing;

use Illuminate\Contracts\Validation\ValidationRule;

/** "Undo upfront payment (recorded by mistake)" on the Billing tab (owner 2026-11-05): the reason is required. */
class UndoUpfrontPaymentRequest extends BillingRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Enter why the upfront payment is undone.',
            'reason.max' => 'Keep the reason under 500 characters.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
