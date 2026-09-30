<?php

namespace App\Http\Requests\App\Compliance;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/** Close or reopen a product recall (module 5.7). Route: `company.can:compliance.manage`; every shop only. */
class RecallStatusRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:open,closed'],
            'returned_qty' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
