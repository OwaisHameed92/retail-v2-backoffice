<?php

namespace App\Domain\Anomalies\Actions;

use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Detectors\CashShortfalls;
use App\Domain\Anomalies\Detectors\Detector;
use App\Domain\Anomalies\Detectors\NegativeStock;
use App\Domain\Anomalies\Detectors\OutOfHours;
use App\Domain\Anomalies\Detectors\PriceOverrides;
use App\Domain\Anomalies\Detectors\SalesDrop;
use App\Domain\Anomalies\Detectors\SalesGap;
use App\Domain\Anomalies\Detectors\StaffExceptions;
use App\Domain\Anomalies\Detectors\UnlinkedRefunds;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The anomaly checks (module 6.6), run by `anomalies:detect`: hourly (today so far: sales gaps, sales outside hours)
 * and daily at 06:30 London (yesterday, every detector that runs daily). For each trading business (not suspended or
 * cancelled) with active shops: the detectors inside its company scope (one failing detector is reported and the
 * rest still run), then RecordAnomalies (dedupe) and DispatchAnomalyAlerts (serious ones straight away).
 * Deterministic: no AI is involved in finding anything.
 */
class DetectAnomalies
{
    /** @var list<class-string<Detector>> */
    public const DETECTORS = [
        StaffExceptions::class,
        CashShortfalls::class,
        SalesGap::class,
        SalesDrop::class,
        PriceOverrides::class,
        NegativeStock::class,
        UnlinkedRefunds::class,
        OutOfHours::class,
    ];

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAnomalies $record,
        private readonly DispatchAnomalyAlerts $dispatch,
    ) {}

    /**
     * @param  list<string>|null  $companyIds  only these businesses; null = all
     * @return array{companies: int, found: int, raised: int, notified: int, emailed: int}
     */
    public function handle(string $mode, ?CarbonImmutable $now = null, ?array $companyIds = null): array
    {
        if (! in_array($mode, [DetectionWindow::HOURLY, DetectionWindow::DAILY], true)) {
            throw new InvalidArgumentException('Mode must be hourly or daily.');
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $today = TradingDay::today($now);
        $day = ($mode === DetectionWindow::DAILY ? $today->subDay() : $today)->format('Y-m-d');
        $totals = ['companies' => 0, 'found' => 0, 'raised' => 0, 'notified' => 0, 'emailed' => 0];

        Company::query()
            ->whereNotIn('status', [CompanyStatus::Suspended->value, CompanyStatus::Cancelled->value])
            ->when($companyIds !== null, fn ($q) => $q->whereIn('id', $companyIds ?? []))
            ->chunkById(100, function ($companies) use ($mode, $now, $day, &$totals) {
                foreach ($companies as $company) {
                    $this->company($company, $mode, $now, $day, $totals);
                }
            });

        return $totals;
    }

    /**
     * @param  array{companies: int, found: int, raised: int, notified: int, emailed: int}  $totals
     */
    private function company(Company $company, string $mode, CarbonImmutable $now, string $day, array &$totals): void
    {
        $shops = DB::table('branches')->where('company_id', $company->id)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('name')->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();

        if ($shops === []) {
            return;
        }

        $window = new DetectionWindow($company->id, $shops, $day, $now, $mode);
        $totals['companies']++;

        $this->tenancy->runAs($company, function () use ($company, $window, $mode, $now, &$totals) {
            $findings = [];

            foreach (self::DETECTORS as $class) {
                $detector = app($class);

                if (! $detector->runsIn($mode)) {
                    continue;
                }

                try {
                    $findings = [...$findings, ...$detector->detect($window)];
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $raised = $this->record->handle($company->id, $findings, $now);
            $sent = $this->dispatch->handle($company, $raised, $now);
            $totals['found'] += count($findings);
            $totals['raised'] += count($raised);
            $totals['notified'] += $sent['notified'];
            $totals['emailed'] += $sent['emailed'];
        });
    }
}
