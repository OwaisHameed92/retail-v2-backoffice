<?php

namespace App\Http\Requests\App;

use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Reports\ReportGrouping;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /app/reports/{report}?period=&from=&to=&compare=&till=&group=&view=&page=` (module 4.8). Like the dashboard,
 * the shop is the top-bar switcher's (a one-shop user's own, always), resolved by BusinessContext — never a
 * parameter. A report GET never fails on its filters: unknown values fall back (last 7 days, previous period, by
 * day, first tab, page 1) and TradingRange clamps a custom range.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $page = $this->query('page');

        $this->merge([
            'period' => (TradingPeriod::tryFrom((string) $this->query('period', '')) ?? TradingPeriod::Last7Days)->value,
            'compare' => (TradingCompare::tryFrom((string) $this->query('compare', '')) ?? TradingCompare::PreviousPeriod)->value,
            'group' => (ReportGrouping::tryFrom((string) $this->query('group', '')) ?? ReportGrouping::Day)->value,
            'till' => Ulid::isValid((string) $this->query('till', '')) ? (string) $this->query('till') : null,
            'from' => is_string($this->query('from')) ? $this->query('from') : null,
            'to' => is_string($this->query('to')) ? $this->query('to') : null,
            'view' => is_string($this->query('view')) && preg_match('/^[a-z]{1,10}$/', $this->query('view')) === 1 ? $this->query('view') : '',
            'page' => is_string($page) && ctype_digit($page) ? min(10000, max(1, (int) $page)) : 1,
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
            'group' => ['required', 'string'],
            'till' => ['nullable', 'string'],
            'from' => ['nullable', 'string', 'max:10'],
            'to' => ['nullable', 'string', 'max:10'],
            'view' => ['nullable', 'string'],
            'page' => ['integer'],
        ];
    }

    public function options(CurrentCompany $current, ?string $branchId, ?string $registerId, bool $export = false): ReportOptions
    {
        $window = BusinessDashboardFilters::resolve(
            $current->require()->id,
            TradingPeriod::from((string) $this->input('period')),
            $this->input('from'),
            $this->input('to'),
            TradingCompare::from((string) $this->input('compare')),
            $branchId,
            $registerId,
        );

        return new ReportOptions($window, ReportGrouping::from((string) $this->input('group')), (string) $this->input('view'), (int) $this->input('page'), $export);
    }
}
