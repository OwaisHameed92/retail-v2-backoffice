<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The checkout's choice of click & collect or delivery, and the postcode to deliver to (OS-9). Whether the shop
 * delivers there, and for how much, is worked out on the server when the page is shown (DeliveryQuote).
 */
class CheckoutMethodRequest extends FormRequest
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
        return [
            'fulfilment' => ['required', 'string', 'in:collection,delivery'],
            'postcode' => ['nullable', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['fulfilment.*' => 'Choose click & collect or delivery.'];
    }
}
