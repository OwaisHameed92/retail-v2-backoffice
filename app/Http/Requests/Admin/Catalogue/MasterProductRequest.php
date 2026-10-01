<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Domain\MasterCatalogue\Support\PackSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A master catalogue product from the admin form, or the details an admin checked when approving a till's barcode
 * (no barcode then: it is the contribution's). Formats only; SaveMasterProduct checks the barcode's check digit,
 * in-store prefixes and that no other row has it. Route: `can:catalogue.manage`.
 */
class MasterProductRequest extends FormRequest
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
            'barcode' => [$this->routeIs('admin.catalogue.contributions.approve') ? 'prohibited' : 'required', 'nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:120'],
            'size_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'size_unit' => ['nullable', Rule::in(PackSize::UNITS)],
            'pack_qty' => ['nullable', 'integer', 'min:1', 'max:500'],
            'department' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:120'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rrp' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'age_rule' => ['required', 'string', 'max:30'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'in_starter_packs' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return [...$this->safe()->except('barcode'), 'in_starter_packs' => $this->boolean('in_starter_packs')]
            + ($this->has('barcode') ? ['barcode' => (string) $this->input('barcode')] : []);
    }
}
