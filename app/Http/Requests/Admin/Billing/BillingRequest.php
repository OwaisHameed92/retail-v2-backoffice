<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for billing changes: `billing.manage` (owner, accounts). Money arrives as "£1,200.50", "1200.5" or a
 * JSON number and is validated as pounds with up to 2 decimal places (never parsed as a float).
 */
abstract class BillingRequest extends FormRequest
{
    /** Pounds with up to 2 decimal places, max £99,999.99. */
    public const MONEY_PATTERN = '/^\d{1,5}(\.\d{1,2})?$/';

    public const MONEY_MESSAGE = 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.';

    public function authorize(): bool
    {
        return $this->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false;
    }

    public static function cleanMoney(mixed $value): mixed
    {
        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => str_replace(['£', ',', ' '], '', trim($value)),
            default => $value,
        };
    }
}
