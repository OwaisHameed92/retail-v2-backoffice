<?php

namespace App\Http\Requests\Api\Pda;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /api/pda/resolve-barcode?barcode=`: the scanned barcode (up to 50 characters, as the product form). */
class PdaBarcodeRequest extends FormRequest
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
            'barcode' => ['required', 'string', 'max:50'],
        ];
    }

    public function barcode(): string
    {
        return (string) $this->validated('barcode');
    }
}
