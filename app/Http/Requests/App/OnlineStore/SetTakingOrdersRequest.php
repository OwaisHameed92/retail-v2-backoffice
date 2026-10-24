<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The board's "Taking orders" switch (OS-4, SetTakingOrders). It stops or opens ordering for the whole business, so a
 * one-shop user may not use it. The route checks `orders.online`.
 */
class SetTakingOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CurrentCompany::class)->restrictedBranchId() === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['taking' => ['required', 'boolean']];
    }

    public function taking(): bool
    {
        return $this->boolean('taking');
    }
}
