<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One collection shop's local delivery (OS-9). The route checks `store.manage`; a one-shop user may change their own
 * shop only (403). Everything else is checked by SaveDeliverySettings.
 */
class SaveDeliverySettingsRequest extends FormRequest
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
            'delivery_enabled' => ['required', 'boolean'],
            'delivery_hours' => ['present'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->only(['delivery_enabled', 'delivery_areas', 'delivery_fee', 'delivery_free_over', 'delivery_minimum', 'delivery_hours',
            'delivery_slot_minutes', 'delivery_orders_per_slot', 'delivery_lead_minutes']);
    }
}
