<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Http\Requests\Admin\Billing\BillingRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and editing a plan.
 */
abstract class PlanRequest extends FormRequest
{
    /** Up to 2 decimal places, max 99,999.99 (GB £99,999.99). No floats, no exponents. */
    private const MONEY_PATTERN = '/^\d{1,5}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return $this->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Accept "£1,200.00" (the profile's symbol) and plain JSON numbers; the value is validated as a 2 dp string below.
        $clean = fn (mixed $value) => match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => str_replace([app(Country::class)->symbol(), ',', ' '], '', trim($value)),
            default => $value,
        };

        $this->merge([
            'code' => is_string($this->input('code')) ? strtolower(trim($this->input('code'))) : $this->input('code'),
            'price_monthly' => $clean($this->input('price_monthly')),
            'price_yearly' => $clean($this->input('price_yearly')),
            'setup_fee' => $clean($this->input('setup_fee', '0')),
            'features' => $this->input('features', []),
            'pricing_mode' => $this->input('pricing_mode', PricingMode::PerTill->value),
        ]);

        // The plan type decides which prices apply: setup only has no recurring price, recurring only no fee.
        $type = PlanBillingType::tryFrom((string) $this->input('billing_type'));

        if ($type === PlanBillingType::SetupOnly) {
            $this->merge(['price_monthly' => '0', 'price_yearly' => '0']);
        } elseif ($type === PlanBillingType::RecurringOnly) {
            $this->merge(['setup_fee' => '0']);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('plans', 'code')->ignore($this->ignoredPlan()?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'billing_type' => ['nullable', Rule::enum(PlanBillingType::class)],
            'price_monthly' => ['required', 'string', 'regex:'.self::moneyPattern()],
            'price_yearly' => ['required', 'string', 'regex:'.self::moneyPattern()],
            'setup_fee' => ['required', 'string', 'regex:'.self::moneyPattern()],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'trial_grace_days' => ['required', 'integer', 'min:0', 'max:30'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:60'],
            'features' => ['present', 'array'],
            'features.*' => ['string', 'distinct', Rule::in(Feature::values())],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * A setup fee plan needs a fee above 0; a recurring plan needs a monthly or yearly price above 0.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function ($validator) {
            $type = PlanBillingType::tryFrom((string) $this->input('billing_type'));
            $positive = fn (string $key) => is_string($this->input($key)) && preg_match(self::moneyPattern(), $this->input($key)) === 1 && ! Money::isZero($this->input($key));

            $zero = MoneyFormat::format('0');

            if ($type === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ($type->hasSetupFee() && ! $positive('setup_fee')) {
                $validator->errors()->add('setup_fee', 'Enter the setup fee (above '.$zero.') for this plan type, or choose "Monthly or yearly only".');
            }

            if ($type->recurs() && ! $positive('price_monthly') && ! $positive('price_yearly')) {
                $validator->errors()->add('price_monthly', 'Enter a monthly or yearly price above '.$zero.', or choose "Setup fee only".');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // GB: "Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99." (PK: "in rupees").
        $money = BillingRequest::moneyMessage();

        return [
            'code.regex' => 'Use lower-case letters, numbers and single hyphens only, for example "standard" or "pro-2026".',
            'code.unique' => 'Another plan already uses this code.',
            'price_monthly.regex' => $money,
            'price_yearly.regex' => $money,
            'setup_fee.regex' => $money,
            'features.*.in' => 'Choose features from the list.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'price_monthly' => 'monthly price',
            'price_yearly' => 'yearly price',
            'setup_fee' => 'setup fee',
            'trial_days' => 'trial length',
            'trial_grace_days' => 'trial grace',
            'grace_days' => 'payment grace',
            'sort_order' => 'sort order',
        ];
    }

    public function toInput(): PlanInput
    {
        /** @var list<string> $features */
        $features = $this->input('features', []);

        return new PlanInput(
            name: $this->string('name')->value(),
            code: $this->string('code')->value(),
            description: $this->filled('description') ? $this->string('description')->value() : null,
            priceMonthly: $this->string('price_monthly')->value(),
            priceYearly: $this->string('price_yearly')->value(),
            features: $features,
            trialDays: $this->integer('trial_days'),
            trialGraceDays: $this->integer('trial_grace_days'),
            graceDays: $this->integer('grace_days'),
            isActive: $this->boolean('is_active'),
            isPublic: $this->boolean('is_public'),
            sortOrder: $this->integer('sort_order'),
            setupFee: $this->string('setup_fee')->value(),
            pricingMode: PricingMode::from($this->string('pricing_mode')->value()),
            billingType: PlanBillingType::tryFrom($this->string('billing_type')->value()),
        );
    }

    /** The plan being edited, whose own code is allowed. */
    protected function ignoredPlan(): ?Plan
    {
        return null;
    }

    /** GB: up to 99,999.99 as always; another country's currency (PKR) allows larger amounts. */
    private static function moneyPattern(): string
    {
        return app(Country::class)->is(Country::DEFAULT) ? self::MONEY_PATTERN : BillingRequest::LARGE_MONEY_PATTERN;
    }
}
