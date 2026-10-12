<?php

use App\Domain\Sales\Actions\BuildSalesExport;
use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Jobs\BuildSalesExportJob;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Audit DATA-1: a second copy of BuildSalesExportJob (the queue handing a still-running job to another worker) must
 * not truncate or rewrite the file the first copy is writing. The export is claimed atomically and the CSV is
 * written to a partial file of its own, renamed into place when complete.
 */

beforeEach(function () {
    Storage::fake(SalesExport::DISK);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds] = T::tenant();
    H::basket($this->company, $this->leeds, T::TILL_1, 500001, '2026-09-24T09:00:00Z');
});

/** An export row as a job would find it; `$claimedAt` = when a copy set it running (its updated_at). */
function exportIn(Company $company, ExportStatus $status, ?string $claimedAt = null): SalesExport
{
    $export = new SalesExport(['user_id' => null, 'status' => $status, 'filters' => (new SaleFilters('2026-09-19', '2026-09-25'))->toArray()]);
    $export->company_id = $company->id;
    $export->save();

    if ($claimedAt !== null) {
        DB::table('sales_exports')->where('id', $export->id)->update(['updated_at' => $claimedAt]);
    }

    return SalesExport::withoutCompanyScope()->findOrFail($export->id);
}

function runExportJob(SalesExport $export): void
{
    app()->call([new BuildSalesExportJob($export->id, $export->company_id), 'handle']);
}

/** @return list<string> */
function exportFiles(Company $company): array
{
    return Storage::disk(SalesExport::DISK)->files("sales-exports/{$company->id}");
}

test('a copy of the job that finds the export running (another worker has it) does nothing and leaves the file alone', function () {
    $export = exportIn($this->company, ExportStatus::Running, now('UTC')->subMinutes(5)->format('Y-m-d H:i:s'));
    $file = "sales-exports/{$this->company->id}/{$export->id}.csv";
    Storage::disk(SalesExport::DISK)->put($file, 'half written by the first copy');

    runExportJob($export);

    $after = SalesExport::withoutCompanyScope()->findOrFail($export->id);
    expect($after->status)->toBe(ExportStatus::Running)
        ->and($after->path)->toBeNull()
        ->and(Storage::disk(SalesExport::DISK)->get($file))->toBe('half written by the first copy')
        ->and(exportFiles($this->company))->toBe([$file]);

    expect(app(CurrentCompany::class)->runAs($this->company, fn () => app(BuildSalesExport::class)->handle($after)))->toBeNull();
});

test('only one of two copies claims a queued export; the CSV is renamed into place with no partial file left', function () {
    $export = exportIn($this->company, ExportStatus::Queued);
    $build = fn () => app(CurrentCompany::class)->runAs($this->company, fn () => app(BuildSalesExport::class)->handle(SalesExport::query()->findOrFail($export->id)));

    expect($build())->not->toBeNull()
        ->and($build())->toBeNull(); // the second copy: the export is no longer queued

    $after = SalesExport::withoutCompanyScope()->findOrFail($export->id);
    expect($after->status)->toBe(ExportStatus::Ready)
        ->and($after->row_count)->toBe(1)
        ->and(exportFiles($this->company))->toBe(["sales-exports/{$this->company->id}/{$export->id}.csv"])
        ->and(Storage::disk(SalesExport::DISK)->get((string) $after->path))->toContain('LDS-01-500001');
});

test('an export left running by a copy its timeout killed is built by the retry', function () {
    $stale = now('UTC')->subSeconds(BuildSalesExportJob::TIMEOUT + 100)->format('Y-m-d H:i:s');
    $export = exportIn($this->company, ExportStatus::Running, $stale);

    runExportJob($export);

    $after = SalesExport::withoutCompanyScope()->findOrFail($export->id);
    expect($after->status)->toBe(ExportStatus::Ready)
        ->and(Storage::disk(SalesExport::DISK)->get((string) $after->path))->toContain('LDS-01-500001');
});

test('an error puts the export back to queued for the retry, removes the partial file, and the retry builds it', function () {
    $export = exportIn($this->company, ExportStatus::Queued);
    $failed = false;
    DB::listen(function (QueryExecuted $q) use (&$failed) {
        if (! $failed && preg_match('/from [`"]sales[`"]/', $q->sql) === 1) {
            $failed = true;

            throw new RuntimeException('Simulated lost connection');
        }
    });

    expect(fn () => runExportJob($export))->toThrow(RuntimeException::class, 'Simulated lost connection');
    expect(SalesExport::withoutCompanyScope()->findOrFail($export->id)->status)->toBe(ExportStatus::Queued)
        ->and(exportFiles($this->company))->toBe([]);

    runExportJob($export);

    expect(SalesExport::withoutCompanyScope()->findOrFail($export->id)->status)->toBe(ExportStatus::Ready)
        ->and(exportFiles($this->company))->toBe(["sales-exports/{$this->company->id}/{$export->id}.csv"]);
});
