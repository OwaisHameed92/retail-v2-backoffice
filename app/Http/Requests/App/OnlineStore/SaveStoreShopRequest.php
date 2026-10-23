<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One collection shop's click & collect settings (OS-3). The route checks `store.manage`; a one-shop user may change
 * their own shop only (403). Hours and numbers are checked by SaveStoreShop.
 */
class SaveStoreShopRequest extends FormRequest
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
        return [
            'opening_hours' => ['present', 'array'],
            'opening_hours.*' => ['nullable', 'array'],
            'prep_minutes' => ['required'],
            'slot_minutes' => ['required'],
            'orders_per_slot' => ['required'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->only(['opening_hours', 'prep_minutes', 'slot_minutes', 'orders_per_slot', 'enabled']);
    }
}
