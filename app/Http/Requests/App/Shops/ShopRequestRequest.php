<?php

namespace App\Http\Requests\App\Shops;

use App\Domain\Shared\Country\ContactRules;
use App\Domain\Shops\Data\ShopRequest;
use App\Domain\Shops\Enums\ShopRequestKind;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Ask for more tills / another shop" (module 4.7). The route checks `shops.manage`; SendShopRequest applies the
 * one-shop rules and finds the shop in the company scope.
 */
class ShopRequestRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ShopRequestKind::class)],
            'tills' => ['required', 'integer', 'min:1', 'max:'.ShopRequest::MAX_TILLS],
            'branch_id' => ['nullable', 'required_if:kind,'.ShopRequestKind::MoreTills->value, 'string', 'max:26'],
            'new_shop_name' => ['nullable', 'required_if:kind,'.ShopRequestKind::NewShop->value, 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'regex:'.ContactRules::phonePattern(TenantRules::PHONE_PATTERN)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required_if' => 'Choose the shop that needs more tills.',
            'new_shop_name.required_if' => 'Tell us where the new shop is.',
            'tills.max' => 'Ask for up to '.ShopRequest::MAX_TILLS.' tills at a time.',
            'phone.regex' => ContactRules::phoneText('Enter a phone number like 0113 496 0000.'),
        ];
    }

    public function shopRequest(): ShopRequest
    {
        return ShopRequest::fromArray($this->validated());
    }
}
