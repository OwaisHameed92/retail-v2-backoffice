<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Enums\BillingCycle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class BillingSettingsRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $emails = $this->input('emails');

        if (is_string($emails)) {
            $emails = preg_split('/[\s,;]+/', $emails) ?: [];
        }

        $this->merge([
            'emails' => is_array($emails) ? array_values(array_filter(array_map(fn (mixed $email) => is_string($email) ? trim($email) : $email, $emails), fn (mixed $email) => $email !== '' && $email !== null)) : [],
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'billing_name' => ['nullable', 'string', 'max:191'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'emails' => ['present', 'array', 'max:5'],
            'emails.*' => ['string', 'email:rfc', 'max:191', 'distinct:ignore_case'],
            'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:90'],
            'vat_applies' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emails.max' => 'Add up to 5 billing emails.',
            'emails.*.email' => 'Enter valid email addresses, separated by commas.',
            'emails.*.distinct' => 'Each email only once.',
            'payment_terms_days.max' => 'Payment terms can be at most 90 days.',
        ];
    }

    public function toInput(): BillingSettingsInput
    {
        /** @var list<string> $emails */
        $emails = $this->validated('emails');

        return new BillingSettingsInput(
            billingName: $this->validated('billing_name'),
            billingAddress: $this->validated('billing_address'),
            emails: $emails,
            cycle: BillingCycle::from((string) $this->validated('cycle')),
            paymentTermsDays: (int) $this->validated('payment_terms_days'),
            vatApplies: (bool) $this->validated('vat_applies'),
        );
    }
}
