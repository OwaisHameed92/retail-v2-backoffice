<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A public store basket change (OS-2): a product id (when adding) and a quantity, kg for weighed products (0 on an
 * update removes the line). The basket rules (steps, limits, shown and orderable at the shop) are the action's
 * (BasketRules). Public: no sign-in.
 */
class BasketLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $quantity = ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'];

        // Adding needs the product and more than none; an update may set 0 (remove the line).
        return $this->isMethod('post')
            ? ['product' => ['required', 'string', 'regex:/^[0-9A-Za-z]{26}$/'], 'quantity' => [...$quantity, 'not_regex:/^0+(\.0+)?$/']]
            : ['quantity' => $quantity];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product.required' => 'Choose a product.',
            'product.regex' => 'That product is not available.',
            'quantity.required' => 'Enter a quantity.',
            'quantity.regex' => 'Enter a quantity like 2 or 1.25.',
            'quantity.not_regex' => 'Enter a quantity above zero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $quantity = $this->input('quantity');

        if (is_int($quantity) || is_float($quantity)) {
            $this->merge(['quantity' => (string) $quantity]);
        }
    }
}
