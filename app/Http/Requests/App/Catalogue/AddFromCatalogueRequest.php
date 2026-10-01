<?php

namespace App\Http\Requests\App\Catalogue;

use App\Domain\MasterCatalogue\Actions\AddFromCatalogue;
use App\Domain\MasterCatalogue\Data\PriceRule;
use App\Domain\MasterCatalogue\Enums\StarterPack;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Validation\Rule;

/**
 * "Add from catalogue" and the starter pack: the picked barcodes (with any price or cost typed for one), or the starter
 * pack and its ticked departments; the price rule; the department mapping. Route: `company.can:catalogue.manage`; a
 * one-shop user may not (CompanyWideWriteRequest). Formats only: AddFromCatalogue checks the business's data.
 */
class AddFromCatalogueRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $starter = $this->routeIs('app.products.starter.add');

        return [
            'items' => [$starter ? 'prohibited' : 'required', 'array', 'min:1', 'max:'.AddFromCatalogue::MAX_ITEMS],
            'items.*.barcode' => ['required', 'string', 'max:20'],
            'items.*.sell_price' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'items.*.cost_price' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'pack' => [$starter ? 'required' : 'prohibited', Rule::enum(StarterPack::class)],
            'include' => [$starter ? 'required' : 'prohibited', 'array', 'min:1', 'max:200'],
            'include.*' => ['string', 'max:120'],
            'price_rule' => ['required', Rule::in(['rrp', 'margin'])],
            'margin' => ['required_if:price_rule,margin', 'nullable', 'numeric', 'min:0', 'max:95'],
            'end_in_9' => ['boolean'],
            'departments' => ['nullable', 'array', 'max:200'],
            'departments.*' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Pick at least one product.',
            'items.max' => 'Add up to '.AddFromCatalogue::MAX_ITEMS.' products at a time.',
            'include.required' => 'Tick at least one department.',
            'margin.required_if' => 'Enter the margin you want.',
        ];
    }

    public function priceRule(): PriceRule
    {
        return new PriceRule((string) $this->input('price_rule'), (float) ($this->input('margin') ?? 30), $this->boolean('end_in_9'));
    }

    /**
     * @return list<array{barcode: string, sell_price?: string|null, cost_price?: string|null}>
     */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'barcode' => (string) $item['barcode'],
            'sell_price' => isset($item['sell_price']) ? (string) $item['sell_price'] : null,
            'cost_price' => isset($item['cost_price']) ? (string) $item['cost_price'] : null,
        ], (array) $this->validated('items', [])));
    }

    /**
     * @return array<string, string|null>
     */
    public function mapping(): array
    {
        $map = [];

        foreach ((array) $this->validated('departments', []) as $name => $id) {
            $map[(string) $name] = $id === null || $id === '' ? null : (string) $id;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function included(): array
    {
        return array_values(array_map('strval', (array) $this->validated('include', [])));
    }
}
