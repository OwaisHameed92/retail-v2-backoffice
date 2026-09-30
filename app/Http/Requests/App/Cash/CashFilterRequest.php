<?php

namespace App\Http\Requests\App\Cash;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Cash and Z screens (module 5.4). The route checks `cash.view`. Filters are read leniently (bookmarkable list
 * URLs); a one-shop user is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class CashFilterRequest extends FormRequest
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

    public function filters(): CashFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return CashFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }

    public function page(): int
    {
        return max(1, min(10000, $this->integer('page', 1)));
    }

    /** Rows per page for the lists paged in memory (10, 25, 50 or 100; default 25). */
    public function perPage(): int
    {
        return TableQuery::from($this)->defaultPerPage(25)->perPage();
    }
}
