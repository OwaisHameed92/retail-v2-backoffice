<?php

namespace App\Http\Requests\App\Compliance;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * Raise or edit a product recall (module 5.7). Route: `company.can:compliance.manage`. A recall goes to every shop's
 * tills, so a one-shop user may not raise one (403, CompanyWideWriteRequest).
 */
class ProductRecallRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:40'],
            'product_id' => ['nullable', 'string', 'max:64'],
            'product_name' => ['nullable', 'required_without:product_id', 'string', 'max:255'],
            'batch_code' => ['nullable', 'string', 'max:100'],
            'expiry_from' => ['nullable', 'date_format:Y-m-d'],
            'expiry_to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:1000'],
            'supplier_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'product_name.required_without' => 'Pick a product or enter its name.',
            'reason.required' => 'Say why the product is recalled.',
        ];
    }
}
