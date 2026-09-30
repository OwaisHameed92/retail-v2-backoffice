<?php

namespace App\Http\Requests\App\Purchasing;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/** Sending or cancelling a head-office order (module 5.2). Route: `company.can:purchasing.manage`; every shop only. */
class OrderStatusRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
