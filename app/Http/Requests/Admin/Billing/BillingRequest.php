<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for billing changes: `billing.manage` (owner, accounts). Money arrives as "£1,200.50", "1200.5" or a
 * JSON number (with the country profile's symbol) and is validated with up to 2 decimal places (never as a float).
 */
abstract class BillingRequest extends FormRequest
{
    /** Up to 2 decimal places, max 99,999.99 (GB £99,999.99). */
    public const MONEY_PATTERN = '/^\d{1,5}(\.\d{1,2})?$/';

    /** GB: "Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99." */
    public static function moneyMessage(): string
    {
        return 'Enter an amount in '.app(Country::class)->currencyName().' with up to 2 decimal places, for example 30 or 29.99.';
    }

    /** GB: "Enter an amount above £0.00." */
    public static function aboveZeroMessage(): string
    {
        return 'Enter an amount above '.MoneyFormat::format('0').'.';
    }

    public function authorize(): bool
    {
        return $this->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false;
    }

    public static function cleanMoney(mixed $value): mixed
    {
        return match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => str_replace([app(Country::class)->symbol(), ',', ' '], '', trim($value)),
            default => $value,
        };
    }
}
