<?php

namespace App\Http\Requests\App\Purchasing;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * A head-office order from the portal form (module 5.2). Route: `company.can:purchasing.manage`; a one-shop manager
 * may look but not order for a shop (403, CompanyWideWriteRequest). Ids are checked against this business by
 * DraftHeadOfficeOrder; money and quantities have the till's scale (cost 4 dp).
 */
class HeadOfficeOrderRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shopId' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'size:26'],
            'supplierId' => ['required', 'string', 'max:64'],
            'status' => ['required', 'in:draft,sent'],
            'expectedDate' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.productId' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.orderedCases' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.caseQty' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.looseUnits' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'lines.*.unitCost' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,4'],
            'lines.*.vatRateId' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one product.',
            'lines.min' => 'Add at least one product.',
            'lines.*.productId.distinct' => 'Each product may be on the order once.',
            'lines.*.unitCost.decimal' => 'Enter the cost with up to 4 decimal places.',
        ];
    }
}
