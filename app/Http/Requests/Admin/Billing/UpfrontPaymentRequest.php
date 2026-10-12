<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Http\Requests\Admin\UpfrontPaymentRules;
use Illuminate\Contracts\Validation\ValidationRule;

/** "Record upfront payment" on the Billing tab (module 1.13). */
class UpfrontPaymentRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(UpfrontPaymentRules::clean($this) + ['upfront_record' => true]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Audit BILL-9: made when the form opens; the same key never records a second payment.
        return UpfrontPaymentRules::rules() + ['idempotency_key' => ['nullable', 'string', 'uuid']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UpfrontPaymentRules::messages();
    }

    public function toPayment(): UpfrontPayment
    {
        $payment = UpfrontPaymentRules::payment($this) ?? new UpfrontPayment(null, PaymentMethod::Cash);

        return new UpfrontPayment($payment->setupFee, $payment->method, $payment->reference, $payment->receivedAt, $this->idempotencyKey());
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) ? $key : null;
    }
}
