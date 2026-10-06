<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Data\PricingOverride;
use App\Domain\Plans\Enums\PricingMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/** A company's own pricing (module 1.13): each field blank = the plan's. */
class PricingRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $blank = fn (mixed $value) => $value === null || (is_string($value) && trim($value) === '');

        $this->merge([
            'pricing_mode' => $blank($this->input('pricing_mode')) ? null : $this->input('pricing_mode'),
            'price_monthly' => $blank($this->input('price_monthly')) ? null : self::cleanMoney($this->input('price_monthly')),
            'price_yearly' => $blank($this->input('price_yearly')) ? null : self::cleanMoney($this->input('price_yearly')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pricing_mode' => ['nullable', Rule::enum(PricingMode::class)],
            'price_monthly' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
            'price_yearly' => ['nullable', 'string', 'regex:'.self::MONEY_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['price_monthly.regex' => self::moneyMessage(), 'price_yearly.regex' => self::moneyMessage()];
    }

    public function toOverride(): PricingOverride
    {
        $mode = $this->validated('pricing_mode');
        $monthly = $this->validated('price_monthly');
        $yearly = $this->validated('price_yearly');

        return new PricingOverride(
            mode: is_string($mode) ? PricingMode::from($mode) : null,
            priceMonthly: is_string($monthly) ? $monthly : null,
            priceYearly: is_string($yearly) ? $yearly : null,
        );
    }
}
