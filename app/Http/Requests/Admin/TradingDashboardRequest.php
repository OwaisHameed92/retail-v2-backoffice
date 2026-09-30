<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\TradingFilters;
use App\Domain\Admin\Enums\TradingCompare;
use App\Domain\Admin\Enums\TradingPeriod;
use App\Domain\Shared\Support\Ulid;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /admin/trading?period=&from=&to=&compare=&company=&branch=` (module 3.2). A dashboard GET never fails on
 * its filters: an unknown period falls back to Today, an unknown compare to the previous period, a malformed id is
 * dropped, and a bad custom range becomes the last 7 days (TradingFilters clamps the rest).
 */
class TradingDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $id = fn (string $key) => Ulid::isValid((string) $this->query($key, '')) ? (string) $this->query($key) : null;

        $this->merge([
            'period' => (TradingPeriod::tryFrom((string) $this->query('period', '')) ?? TradingPeriod::Today)->value,
            'compare' => (TradingCompare::tryFrom((string) $this->query('compare', '')) ?? TradingCompare::PreviousPeriod)->value,
            'company' => $id('company'),
            'branch' => $id('branch'),
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
            'company' => ['nullable', 'string'],
            'branch' => ['nullable', 'string'],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function filters(?string $companyId, ?string $branchId): TradingFilters
    {
        return TradingFilters::resolve(
            TradingPeriod::from((string) $this->input('period')),
            $this->input('from'),
            $this->input('to'),
            TradingCompare::from((string) $this->input('compare')),
            $companyId,
            $branchId,
        );
    }
}
