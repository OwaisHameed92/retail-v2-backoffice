<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Handheld\Support\PdaPage;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query of the handheld lists: `search`, `barcode` (products), `status` and `client_reference` (purchase orders), `page`, `per_page`
 * (default 25, at most 100).
 */
class PdaListRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:20'],
            'client_reference' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'string', 'max:64'],
            'purchase_order_id' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('handheld.max_per_page', 100)],
        ];
    }

    public function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }

    public function perPage(): int
    {
        return PdaPage::perPage($this->validated('per_page'));
    }

    public function pageNumber(): int
    {
        return max(1, (int) ($this->validated('page') ?? 1));
    }
}
