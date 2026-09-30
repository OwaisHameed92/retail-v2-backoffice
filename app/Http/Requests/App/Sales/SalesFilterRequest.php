<?php

namespace App\Http\Requests\App\Sales;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The sales list and its CSV export (module 4.6). The route checks `sales.view`. Filters are read leniently (a bad
 * value is dropped, not an error: these are bookmarkable list URLs); a one-shop user is pinned to their shop and the
 * shop defaults to the one picked in the top bar.
 */
class SalesFilterRequest extends FormRequest
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

    public function filters(): SaleFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return SaleFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }
}
