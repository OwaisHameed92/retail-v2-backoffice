<?php

namespace App\Http\Requests\App\Accounts;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Data\VatQuarter;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Accounts screens (module 5.5). The route checks `accounts.view`. Filters are read leniently (bookmarkable
 * URLs); a one-shop user is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class AccountsFilterRequest extends FormRequest
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

    public function filters(): AccountsFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return AccountsFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }

    public function quarter(): VatQuarter
    {
        return VatQuarter::fromQuery($this->query('quarter'), $this->query('stagger'));
    }
}
