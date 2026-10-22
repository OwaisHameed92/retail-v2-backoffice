<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Country\TillProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/pda/products/{id}/shop-price {retail}`: this shop's own price, or `null` to go back to the business
 * price. The product form's price rule (TillProfile::priceRule: 2 decimal places, up to 99,999.99 on GB and
 * 9,999,999.99 on PK); a JSON number or a string, never an exponent.
 */
class PdaShopPriceRequest extends FormRequest
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
            'retail' => ['present', 'nullable', TillProfile::priceRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retail.present' => 'Send "retail": the shop\'s price, or null to use the business price.',
            'retail.regex' => LocalText::currency('Enter a price in pounds, like 1.25, up to '.MoneyFormat::format(TillProfile::priceMax()).'.'),
        ];
    }

    /** The new shop price, or null to remove it. */
    public function retail(): ?string
    {
        $value = $this->validated('retail');

        return $value === null ? null : (string) $value;
    }
}
