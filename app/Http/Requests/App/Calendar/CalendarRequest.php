<?php

namespace App\Http\Requests\App\Calendar;

use App\Domain\Calendar\Data\CalendarFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The calendar screens (module 5.9). The route checks `calendar.manage`. Filters are read leniently; a one-shop user
 * is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class CalendarRequest extends FormRequest
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

    public function filters(): CalendarFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return CalendarFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }

    /** Every shop's sales in an event comparison: only when asked and the user is not limited to one shop. */
    public function everyShop(): bool
    {
        return $this->query('shops') === 'all' && app(CurrentCompany::class)->restrictedBranchId() === null;
    }
}
