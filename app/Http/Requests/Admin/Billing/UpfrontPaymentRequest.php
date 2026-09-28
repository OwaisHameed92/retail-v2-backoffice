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
        return UpfrontPaymentRules::rules();
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
        return UpfrontPaymentRules::payment($this) ?? new UpfrontPayment(null, PaymentMethod::Cash);
    }
}
