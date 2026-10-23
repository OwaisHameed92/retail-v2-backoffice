<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public store's collection shop choice (OS-2, checkout's shop picker): one of the store's open shops.
 */
class ChooseStoreShopRequest extends FormRequest
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
        return ['shopId' => ['required', 'string', 'regex:/^[0-9A-Za-z]{26}$/']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['shopId.required' => 'Choose a shop.', 'shopId.regex' => 'Choose one of the shops listed.'];
    }
}
