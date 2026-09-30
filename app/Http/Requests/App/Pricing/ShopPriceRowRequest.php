<?php

namespace App\Http\Requests\App\Pricing;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\BranchPrice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An action on one shop price row (cancel a scheduled price; module 4.3). Route: `company.can:prices.manage`. A
 * one-shop user may act only on their own shop's rows (403); another business's row is not found (404).
 */
class ShopPriceRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return $restricted === null || BranchPrice::query()->whereKey((string) $this->route('row'))->value('branch_id') === $restricted;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
