<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Country\TillProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/pda/products/{id}/units/{product_unit_id}/price {retail_price, scope}`: `scope` business (default) or
 * shop; `retail_price` with the product form's price rule (TillProfile::priceRule), or `null` with `scope: shop` to
 * end this shop's own price for the unit.
 */
class PdaUnitPriceRequest extends FormRequest
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
            'scope' => ['nullable', 'in:business,shop'],
            'retail_price' => ['present', 'nullable', 'required_unless:scope,shop', TillProfile::priceRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scope.in' => 'The scope is business or shop.',
            'retail_price.present' => 'Send "retail_price".',
            'retail_price.required_unless' => 'Enter the unit\'s price (only a shop price can be removed with null).',
            'retail_price.regex' => LocalText::currency('Enter a price in pounds, like 1.25, up to '.MoneyFormat::format(TillProfile::priceMax()).'.'),
        ];
    }

    public function scope(): string
    {
        return $this->validated('scope') === 'shop' ? 'shop' : 'business';
    }

    public function retailPrice(): ?string
    {
        $value = $this->validated('retail_price');

        return $value === null ? null : (string) $value;
    }
}
