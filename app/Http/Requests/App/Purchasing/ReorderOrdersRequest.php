<?php

namespace App\Http\Requests\App\Purchasing;

use App\Domain\Purchasing\Reorder\ReorderOrderPlan;
use App\Domain\Shared\Rules\ValidUlid;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * Draft orders from the reorder suggestions (module 6.4). Route: `company.can:purchasing.manage`; a one-shop user
 * may look but not order (403, as head-office orders in 5.2). Ids are checked against this business by
 * ReorderOrderPlan; cases are whole cases.
 */
class ReorderOrdersRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.ReorderOrderPlan::MAX_LINES],
            'lines.*.shopId' => ['required', 'string', new ValidUlid],
            'lines.*.supplierId' => ['required', 'string', 'max:64'],
            'lines.*.productId' => ['required', 'string', 'max:64'],
            'lines.*.cases' => ['required', 'integer', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Choose at least one product to order.',
            'lines.min' => 'Choose at least one product to order.',
            'lines.*.cases.integer' => 'Enter whole cases.',
            'lines.*.cases.max' => 'That is more than 10,000 cases.',
        ];
    }

    /**
     * @return list<array{shopId: string, supplierId: string, productId: string, cases: int}>
     */
    public function lines(): array
    {
        return array_values(array_map(fn (array $l) => [
            'shopId' => (string) $l['shopId'], 'supplierId' => (string) $l['supplierId'], 'productId' => (string) $l['productId'], 'cases' => (int) $l['cases'],
        ], (array) $this->validated('lines')));
    }
}
