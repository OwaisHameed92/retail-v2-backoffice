<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Http\Requests\Admin\Billing\BillingRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "Upfront payment" part of the tenant wizard and the trial approval (module 1.13): `upfront_record`,
 * `upfront_amount` (the setup fee, net; blank = the plan's, 0 = nothing to pay), `upfront_method` (cash or bank
 * transfer) and `upfront_reference`. Only billing admins (`billing.manage`) record money; for anyone else the
 * fields are ignored and the setup fee is collected by Direct Debit once the owner sets it up.
 */
final class UpfrontPaymentRules
{
    /**
     * @return array<string, mixed>
     */
    public static function clean(FormRequest $request): array
    {
        $amount = $request->input('upfront_amount');

        return ['upfront_amount' => $amount === null || (is_string($amount) && trim($amount) === '') ? null : BillingRequest::cleanMoney($amount)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'upfront_record' => ['sometimes', 'boolean'],
            'upfront_amount' => ['nullable', 'string', 'regex:'.BillingRequest::MONEY_PATTERN],
            'upfront_method' => ['nullable', 'required_if_accepted:upfront_record', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])],
            'upfront_reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'upfront_amount.regex' => BillingRequest::MONEY_MESSAGE,
            'upfront_method.required_if_accepted' => 'Choose how the upfront payment was made.',
            'upfront_method.in' => 'Choose cash or bank transfer.',
        ];
    }

    public static function payment(FormRequest $request): ?UpfrontPayment
    {
        if (! $request->boolean('upfront_record') || ! ($request->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false)) {
            return null;
        }

        return new UpfrontPayment(
            setupFee: $request->filled('upfront_amount') ? (string) $request->input('upfront_amount') : null,
            method: PaymentMethod::from((string) $request->input('upfront_method')),
            reference: $request->filled('upfront_reference') ? trim((string) $request->input('upfront_reference')) : null,
        );
    }
}
