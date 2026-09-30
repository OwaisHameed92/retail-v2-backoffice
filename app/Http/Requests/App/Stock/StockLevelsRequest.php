<?php

namespace App\Http\Requests\App\Stock;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A product's stock levels (module 5.1): minimum (the low-stock point), most to hold and reorder quantity. Empty =
 * not set. The route checks `stock.manage`.
 */
class StockLevelsRequest extends FormRequest
{
    public const FIELDS = ['min_stock_qty', 'max_stock_qty', 'reorder_qty'];

    private const QTY = 'regex:/^\d{1,8}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_fill_keys(self::FIELDS, ['nullable', 'string', self::QTY]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = 'Enter a quantity of 0 or more, with up to 4 decimal places.';

        return array_fill_keys(array_map(fn (string $f) => $f.'.regex', self::FIELDS), $message);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (self::FIELDS as $key) {
            $value = $this->input($key);
            $clean[$key] = is_int($value) || is_float($value) ? (string) $value : (is_string($value) && trim($value) !== '' ? trim($value) : null);
        }

        $this->merge($clean);
    }

    /**
     * @return array{min_stock_qty: string|null, max_stock_qty: string|null, reorder_qty: string|null}
     */
    public function levels(): array
    {
        $data = $this->validated();
        $get = fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;

        return ['min_stock_qty' => $get('min_stock_qty'), 'max_stock_qty' => $get('max_stock_qty'), 'reorder_qty' => $get('reorder_qty')];
    }
}
