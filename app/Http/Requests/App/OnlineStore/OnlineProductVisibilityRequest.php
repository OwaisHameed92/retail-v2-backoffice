<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Actions\SetOnlineProductsHidden;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;

/**
 * Hide or show the selected products online (OS-3 bulk bar). The ids are checked by SetOnlineProductsHidden. The route
 * checks `store.manage`; a one-shop user may not change what every shop shows (403).
 */
class OnlineProductVisibilityRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.SetOnlineProductsHidden::MAX],
            'ids.*' => ['required', 'string', 'max:26'],
            'hidden' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map('strval', (array) $this->validated('ids')));
    }
}
