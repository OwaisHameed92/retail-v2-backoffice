<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A closed day or special hours for one shop (audit OSA-13). The route checks `store.manage`; a one-shop user may
 * change their own shop only (403). The values are checked by SaveStoreClosure.
 */
class SaveStoreClosureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || $this->route('branch') === $restricted;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function closure(): array
    {
        return $this->only(['date', 'all_day', 'opens_at', 'closes_at', 'reason']);
    }
}
