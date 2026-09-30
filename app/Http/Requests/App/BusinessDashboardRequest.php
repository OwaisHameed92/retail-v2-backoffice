<?php

namespace App\Http\Requests\App;

use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /app?period=&from=&to=&compare=&till=` (module 3.3). The shop is not a parameter: it is the top-bar switcher's
 * (or a one-shop user's own), resolved by BusinessContext. A dashboard GET never fails on its filters: an unknown
 * period falls back to Today, an unknown compare to the previous period, a malformed till id is dropped, and a bad
 * custom range becomes the last 7 days (TradingRange clamps the rest).
 */
class BusinessDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'period' => (TradingPeriod::tryFrom((string) $this->query('period', '')) ?? TradingPeriod::Today)->value,
            'compare' => (TradingCompare::tryFrom((string) $this->query('compare', '')) ?? TradingCompare::PreviousPeriod)->value,
            'till' => Ulid::isValid((string) $this->query('till', '')) ? (string) $this->query('till') : null,
            'from' => is_string($this->query('from')) ? $this->query('from') : null,
            'to' => is_string($this->query('to')) ? $this->query('to') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['required', 'string'],
            'compare' => ['required', 'string'],
            'till' => ['nullable', 'string'],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function filters(CurrentCompany $current, ?string $branchId, ?string $registerId): BusinessDashboardFilters
    {
        return BusinessDashboardFilters::resolve(
            $current->require()->id,
            TradingPeriod::from((string) $this->input('period')),
            $this->input('from'),
            $this->input('to'),
            TradingCompare::from((string) $this->input('compare')),
            $branchId,
            $registerId,
        );
    }
}
