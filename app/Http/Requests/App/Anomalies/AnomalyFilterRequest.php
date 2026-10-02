<?php

namespace App\Http\Requests\App\Anomalies;

use App\Domain\Anomalies\Data\AnomalyFilters;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Unusual activity list (module 6.6). The route checks `reports.view`. Filters are read leniently (bookmarkable
 * URLs); a one-shop user is pinned to their shop and the shop defaults to the one picked in the top bar.
 */
class AnomalyFilterRequest extends FormRequest
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

    public function filters(): AnomalyFilters
    {
        $current = app(ResolveCurrentBranch::class)->handle($this->session());

        return AnomalyFilters::fromRequest($this, app(CurrentCompany::class), $current?->id);
    }
}
