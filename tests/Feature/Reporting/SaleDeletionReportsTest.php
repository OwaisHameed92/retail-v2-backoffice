<?php

use App\Domain\Demo\Actions\ForgetDemoData;
use App\Domain\Reporting\Actions\CheckReports;
use App\Domain\Reporting\Actions\GenerateDemoSales;
use App\Domain\Reporting\Actions\ProcessDirtyReportDays;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Sales deleted outside the push path (demo clean-up) mark their shop-days dirty, so no `rpt_*` row outlives its raw
 * rows ("stored but not in the raw rows" in `reports:check`). Runs on the in-memory test database only.
 */

beforeEach(function () {
    // Wednesday 30 Sept 2026, 15:30 London (BST).
    $this->travelTo(CarbonImmutable::parse('2026-09-30 14:30:00', 'UTC'));
    $this->khan = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $leeds = Branch::factory()->forCompany($this->khan)->create(['code' => 'LDS', 'name' => 'Leeds']);
    Register::factory()->forBranch($leeds)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);

    $this->other = Company::factory()->create(['name' => 'Patel News']);
    $york = Branch::factory()->forCompany($this->other)->create(['code' => 'YRK', 'name' => 'York']);
    Register::factory()->forBranch($york)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
});

/** rpt_* rows of a business, per table. */
function reportRowCounts(string $companyId): array
{
    return collect(ReportTables::names())->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('company_id', $companyId)->count()])->all();
}

it('marks the shop-days of deleted demo sales dirty, and processing them empties their report rows', function () {
    app(GenerateDemoSales::class)->handle($this->khan, 2);
    app(GenerateDemoSales::class)->handle($this->other, 2);
    $otherBefore = reportRowCounts($this->other->id);
    $days = DB::table('sales')->where('company_id', $this->khan->id)->distinct()->get(['branch_id', 'trading_day'])
        ->map(fn ($r) => $r->branch_id.'|'.substr((string) $r->trading_day, 0, 10))->sort()->values()->all();

    expect(DB::table(ReportTables::SALES_DAILY)->where('company_id', $this->khan->id)->count())->toBeGreaterThan(0)
        ->and(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0);

    $forgotten = app(ForgetDemoData::class)->handle($this->khan->id, [ForgetDemoData::STREAMS[1]]);

    $dirty = DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $this->khan->id)->get(['branch_id', 'trading_day'])
        ->map(fn ($r) => $r->branch_id.'|'.substr((string) $r->trading_day, 0, 10))->sort()->values()->all();

    expect(DB::table('sales')->where('company_id', $this->khan->id)->count())->toBe(0)
        ->and($forgotten['oldestDay'])->toBe('2026-09-29')
        ->and($dirty)->toBe($days)
        ->and(DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $this->other->id)->count())->toBe(0);

    // Before processing, the stored rows no longer match the raw rows; after, they do (none left).
    expect(app(CheckReports::class)->handle([$this->khan->id])['mismatches'])->not->toBe([]);

    app(ProcessDirtyReportDays::class)->handle($this->khan->id);

    expect(array_sum(reportRowCounts($this->khan->id)))->toBe(0)
        ->and(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0)
        ->and(app(CheckReports::class)->handle()['mismatches'])->toBe([])
        ->and(reportRowCounts($this->other->id))->toBe($otherBefore);
});

it('leaves no report rows behind when demo:sales --fresh replaces older sales', function () {
    app(GenerateDemoSales::class)->handle($this->khan, 3);
    expect(DB::table(ReportTables::SALES_DAILY)->where('company_id', $this->khan->id)->where('trading_day', '2026-09-28')->count())->toBeGreaterThan(0);

    // A week later, a fresh one-day run removes the old days' sales.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 14:30:00', 'UTC'));
    $result = app(GenerateDemoSales::class)->handle($this->khan, 1, fresh: true);

    $reportDays = collect(ReportTables::names())
        ->flatMap(fn (string $t) => DB::table($t)->where('company_id', $this->khan->id)->distinct()->pluck('trading_day'))
        ->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all();

    expect($result['removed'])->toBeGreaterThan(0)
        ->and($reportDays)->toBe(['2026-10-07'])
        ->and(DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $this->khan->id)->count())->toBe(0)
        ->and(app(CheckReports::class)->handle([$this->khan->id])['mismatches'])->toBe([]);
});
