<?php

namespace App\Http\Requests\App\Compliance;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Compliance screens (module 5.7). The route checks `compliance.view`. Filters are read leniently (bookmarkable
 * list URLs); a one-shop user is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class ComplianceFilterRequest extends FormRequest
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

    public function filters(): ComplianceFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return ComplianceFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }
}
