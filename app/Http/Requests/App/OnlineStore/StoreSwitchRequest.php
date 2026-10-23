<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * "Go live" / "Take offline" and the "Taking orders" switch (OS-3): `on` true or false. The route checks
 * `store.manage`; a one-shop user may not change the business's store (403).
 */
class StoreSwitchRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['on' => ['required', 'boolean']];
    }
}
