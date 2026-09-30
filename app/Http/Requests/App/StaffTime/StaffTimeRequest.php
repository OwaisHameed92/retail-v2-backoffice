<?php

namespace App\Http\Requests\App\StaffTime;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The staff time screens (module 5.6). The route checks `staff.view`. Filters are read leniently (bookmarkable
 * URLs); a one-shop user is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class StaffTimeRequest extends FormRequest
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

    public function filters(): TimeFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return TimeFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }

    public function rotaWeek(): string
    {
        return TimeFilters::rotaWeek($this);
    }

    public function page(): int
    {
        return max(1, min(10000, $this->integer('page', 1)));
    }

    /** Rows per page (10, 25, 50 or 100; default 25). */
    public function perPage(): int
    {
        return TableQuery::from($this)->defaultPerPage(25)->perPage();
    }
}
