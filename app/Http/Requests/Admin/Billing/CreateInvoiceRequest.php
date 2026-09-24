<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Support\BillingDates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class CreateInvoiceRequest extends BillingRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period_start' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before:2100-01-01'],
            'cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'prorate' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'issue' => ['sometimes', 'boolean'],
            'allow_overlap' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['period_start.date_format' => 'Choose the first day of the period.'];
    }

    public function toNewInvoice(): NewInvoice
    {
        $start = $this->input('period_start');

        return new NewInvoice(
            periodStart: is_string($start) && $start !== '' ? BillingDates::date($start) : null,
            cycle: BillingCycle::tryFrom((string) $this->input('cycle')),
            prorate: $this->has('prorate') ? $this->boolean('prorate') : null,
            notes: $this->input('notes'),
            issue: $this->boolean('issue'),
            allowOverlap: $this->boolean('allow_overlap'),
        );
    }
}
