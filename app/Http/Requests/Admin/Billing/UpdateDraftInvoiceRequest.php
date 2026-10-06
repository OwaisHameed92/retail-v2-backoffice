<?php

namespace App\Http\Requests\Admin\Billing;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateDraftInvoiceRequest extends BillingRequest
{
    /** Up to 4 decimal places, above zero, at most 9999. */
    private const QUANTITY_PATTERN = '/^\d{1,4}(\.\d{1,4})?$/';

    /** Unit price may be negative for a discount line. */
    private const PRICE_PATTERN = '/^-?\d{1,5}(\.\d{1,2})?$/';

    /** Pakistan plan P5: rupee prices run larger (see BillingRequest::LARGE_MONEY_PATTERN). */
    private const LARGE_PRICE_PATTERN = '/^-?\d{1,9}(\.\d{1,2})?$/';

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $this->merge(['lines' => array_map(fn (mixed $line) => is_array($line) ? [
                ...$line,
                'quantity' => self::cleanMoney($line['quantity'] ?? null),
                'unit_price' => self::cleanMoney($line['unit_price'] ?? null),
            ] : $line, $lines)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['nullable', 'string', 'max:26'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QUANTITY_PATTERN, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_price' => ['required', 'string', 'regex:'.(self::moneyPattern() === self::MONEY_PATTERN ? self::PRICE_PATTERN : self::LARGE_PRICE_PATTERN)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'An invoice needs at least one line.',
            'lines.min' => 'An invoice needs at least one line.',
            'lines.*.description.required' => 'Describe the line.',
            'lines.*.quantity.regex' => 'Enter a quantity above 0 with up to 4 decimal places.',
            'lines.*.quantity.not_regex' => 'Enter a quantity above 0.',
            'lines.*.unit_price.regex' => self::moneyMessage(),
        ];
    }

    /**
     * @return list<array{id: string|null, description: string, quantity: string, unit_price: string}>
     */
    public function lines(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(fn (array $line) => [
            'id' => isset($line['id']) && is_string($line['id']) ? $line['id'] : null,
            'description' => (string) $line['description'],
            'quantity' => (string) $line['quantity'],
            'unit_price' => (string) $line['unit_price'],
        ], $lines);
    }
}
