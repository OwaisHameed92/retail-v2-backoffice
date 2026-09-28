<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and editing a plan.
 */
abstract class PlanRequest extends FormRequest
{
    /** Pounds with up to 2 decimal places, max £99,999.99. No floats, no exponents. */
    private const MONEY_PATTERN = '/^\d{1,5}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return $this->user('admin')?->can(AdminRole::BILLING_MANAGE) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Accept "£1,200.00" and plain JSON numbers; the value is validated as a 2 dp string below.
        $clean = fn (mixed $value) => match (true) {
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => str_replace(['£', ',', ' '], '', trim($value)),
            default => $value,
        };

        $this->merge([
            'code' => is_string($this->input('code')) ? strtolower(trim($this->input('code'))) : $this->input('code'),
            'price_per_till_monthly' => $clean($this->input('price_per_till_monthly')),
            'price_per_till_yearly' => $clean($this->input('price_per_till_yearly')),
            'setup_fee' => $clean($this->input('setup_fee', '0')),
            'features' => $this->input('features', []),
        ]);
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
            'price_per_till_monthly' => ['required', 'string', 'regex:'.self::MONEY_PATTERN],
            'price_per_till_yearly' => ['required', 'string', 'regex:'.self::MONEY_PATTERN],
            'setup_fee' => ['required', 'string', 'regex:'.self::MONEY_PATTERN],
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        $money = 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.';

        return [
            'code.regex' => 'Use lower-case letters, numbers and single hyphens only, for example "standard" or "pro-2026".',
            'code.unique' => 'Another plan already uses this code.',
            'price_per_till_monthly.regex' => $money,
            'price_per_till_yearly.regex' => $money,
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
            'price_per_till_monthly' => 'monthly price',
            'price_per_till_yearly' => 'yearly price',
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
            pricePerTillMonthly: $this->string('price_per_till_monthly')->value(),
            pricePerTillYearly: $this->string('price_per_till_yearly')->value(),
            features: $features,
            trialDays: $this->integer('trial_days'),
            trialGraceDays: $this->integer('trial_grace_days'),
            graceDays: $this->integer('grace_days'),
            isActive: $this->boolean('is_active'),
            isPublic: $this->boolean('is_public'),
            sortOrder: $this->integer('sort_order'),
            setupFee: $this->string('setup_fee')->value(),
        );
    }

    /** The plan being edited, whose own code is allowed. */
    protected function ignoredPlan(): ?Plan
    {
        return null;
    }
}
