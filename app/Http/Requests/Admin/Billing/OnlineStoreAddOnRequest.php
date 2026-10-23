<?php

namespace App\Http\Requests\Admin\Billing;

use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The online store add-on of a business (OS-6, `billing.manage`): on or off, the monthly price before VAT (blank = the
 * default) and the template (Fresh Market or Butcher's Block; required to switch on a business without a store).
 */
class OnlineStoreAddOnRequest extends BillingRequest
{
    protected function prepareForValidation(): void
    {
        $blank = fn (mixed $value) => $value === null || (is_string($value) && trim($value) === '');

        $this->merge([
            'monthly_price' => $blank($this->input('monthly_price')) ? null : self::cleanMoney($this->input('monthly_price')),
            'template' => $blank($this->input('template')) ? null : $this->input('template'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'on' => ['required', 'boolean'],
            'monthly_price' => ['nullable', 'string', 'regex:'.self::moneyPattern()],
            'template' => ['nullable', 'string', Rule::in(array_map(fn (StoreTemplate $template) => $template->value, OnlineStoreAddOnInput::TEMPLATES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['monthly_price.regex' => self::moneyMessage(), 'template.in' => 'Choose Fresh Market or Butcher’s Block.'];
    }

    public function toInput(): OnlineStoreAddOnInput
    {
        $price = $this->validated('monthly_price');
        $template = $this->validated('template');

        return new OnlineStoreAddOnInput(
            on: $this->boolean('on'),
            monthlyPrice: is_string($price) ? $price : null,
            template: is_string($template) ? StoreTemplate::from($template) : null,
        );
    }
}
