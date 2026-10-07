<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Http\Requests\Admin\Billing\BillingRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P11: the "Setup fee for the added tills" field of the admin Add till / Add branch dialogs (`till_setup_fee`, net;
 * blank = the per-till fee × tills, 0 = waived). Only billing admins (`billing.manage`) may change it; for anyone
 * else it is ignored and the usual fee is invoiced.
 */
final class AddedTillFeeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function clean(FormRequest $request): array
    {
        $amount = $request->input('till_setup_fee');

        return ['till_setup_fee' => $amount === null || (is_string($amount) && trim($amount) === '') ? null : BillingRequest::cleanMoney($amount)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return ['till_setup_fee' => ['nullable', 'string', 'regex:'.BillingRequest::moneyPattern()]];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return ['till_setup_fee.regex' => BillingRequest::moneyMessage()];
    }

    public static function amount(FormRequest $request): ?string
    {
        if (! $request->filled('till_setup_fee') || ! ($request->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false)) {
            return null;
        }

        return (string) $request->input('till_setup_fee');
    }
}
