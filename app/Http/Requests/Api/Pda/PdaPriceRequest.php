<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Country\TillProfile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/pda/products/{id}/price {retail}`: the product form's price rule (TillProfile::priceRule: 2 decimal
 * places, up to 99,999.99 on GB and 9,999,999.99 on PK). A JSON number or a string; never an exponent.
 */
class PdaPriceRequest extends FormRequest
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
            'retail' => ['required', TillProfile::priceRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'retail.regex' => LocalText::currency('Enter a price in pounds, like 1.25, up to '.MoneyFormat::format(TillProfile::priceMax()).'.'),
        ];
    }

    public function retail(): string
    {
        return (string) $this->validated('retail');
    }
}
