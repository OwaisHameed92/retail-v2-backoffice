<?php

namespace App\Http\Requests\App\Shops;

use App\Domain\Shops\Data\ShopDetails;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A shop's details from Shops and tills (module 4.7). The route checks `shops.manage`; UpdateShopDetails refuses a
 * one-shop user editing another shop. Same formats as the admin branch form (TenantRules).
 */
class ShopDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TenantRules::clean($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_intersect_key(TenantRules::branch(), array_flip(ShopDetails::COLUMNS));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages();
    }

    public function details(): ShopDetails
    {
        return ShopDetails::fromArray($this->validated());
    }
}
