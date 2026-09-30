<?php

namespace App\Http\Requests\App\Pricing;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * "Every shop": a new business price, and the shops whose own price ends with it (module 4.3). Route:
 * `company.can:prices.manage`; company-wide, so a one-shop user gets 403 (CompanyWideWriteRequest).
 */
class EveryShopPriceRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'end_shop_ids' => ['present', 'array', 'max:500'],
            'end_shop_ids.*' => ['string', 'size:26', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['price.regex' => 'Enter the price in pounds, e.g. 1.39.'];
    }

    /**
     * @return list<string>
     */
    public function endShopIds(): array
    {
        return array_values(array_map('strval', (array) $this->validated('end_shop_ids', [])));
    }
}
