<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Data\OrderBoardFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The online orders board (OS-4). The route checks `orders.online`. Filters are read leniently (bookmarkable URLs):
 * `shop` (a shop id, or `all`; missing = the shop picked in the top bar) and `day` (Y-m-d, default today). A
 * one-shop user is pinned to their shop.
 */
class OnlineOrderBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function filters(): OrderBoardFilters
    {
        $shop = $this->query('shop');

        if (! is_string($shop)) {
            $shop = app(ResolveCurrentBranch::class)->handle($this->session())?->id;
        }

        return OrderBoardFilters::from(['shop' => $shop, 'day' => $this->query('day')], app(CurrentCompany::class)->restrictedBranchId());
    }
}
