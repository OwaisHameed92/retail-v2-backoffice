<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/pda/products/{id}/cost {cost}`: cost per base unit, ex VAT, as the product form takes it (up to 8 whole
 * digits and 4 decimal places). A JSON number or a string; never an exponent.
 */
class PdaCostRequest extends FormRequest
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
            'cost' => ['required', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cost.regex' => Country::tax(LocalText::currency('Enter the cost in pounds, ex VAT, with up to 4 decimal places, like 0.9800.')),
        ];
    }

    public function cost(): string
    {
        return (string) $this->validated('cost');
    }
}
