<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\PlanOnlineStore;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * "Change plan" on the tenant Billing tab (owner 2026-10-07): the preview (GET, no writes) and the confirm (POST) take
 * the same fields: the plan (active plans only) and the setup fee the admin typed (net; empty = the suggested amount,
 * 0 = waive it). `billing.manage` (BillingRequest). OS-7: the confirm also takes the online store's template, required
 * when the plan includes the online store and the business has no store yet.
 */
class ChangePlanRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $fee = $this->input('setup_fee');

        $this->merge(['setup_fee' => $fee === null || $fee === '' ? null : self::cleanMoney($fee)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'setup_fee' => ['nullable', 'string', 'regex:'.self::moneyPattern()],
            'store_template' => ['nullable', 'string', Rule::in(array_map(fn (StoreTemplate $template) => $template->value, OnlineStoreAddOnInput::TEMPLATES))],
        ];
    }

    /**
     * OS-7: confirming a plan with the online store for a business without a store needs the store's template.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function ($validator) {
            if (! $this->isMethod('post') || $validator->errors()->isNotEmpty() || $this->filled('store_template')) {
                return;
            }

            $company = $this->route('company');
            $plan = Plan::query()->find((string) $this->input('plan_id'));

            if ($company instanceof Company && PlanOnlineStore::includes($plan) && OnlineStoreAddOn::store($company) === null) {
                $validator->errors()->add('store_template', 'Choose the online store’s template: '.$plan?->name.' includes the online store.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_id.required' => 'Choose the plan to move the business to.',
            'plan_id.exists' => 'Choose an active plan.',
            'setup_fee.regex' => self::moneyMessage(),
            'store_template.in' => 'Choose Fresh Market or Butcher’s Block.',
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->findOrFail((string) $this->validated('plan_id'));
    }

    public function storeTemplate(): ?StoreTemplate
    {
        $template = $this->validated('store_template');

        return is_string($template) ? StoreTemplate::tryFrom($template) : null;
    }

    public function setupFee(): ?string
    {
        $fee = $this->validated('setup_fee');

        return is_string($fee) ? $fee : null;
    }
}
