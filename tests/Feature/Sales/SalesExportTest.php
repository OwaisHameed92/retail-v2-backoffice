<?php

use App\Domain\Sales\Actions\QueueSalesExport;
use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 4.6: CSV export of the filtered sales list. Up to SalesExport::STREAM_ROWS it downloads at once; above, a
 * queued job builds a private file only the user who asked may download (the test queue runs jobs straight away).
 */

beforeEach(function () {
    Storage::fake(SalesExport::DISK);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds, $this->bradford] = T::tenant();
    H::basket($this->company, $this->leeds, T::TILL_1, 500001, '2026-09-24T09:00:00Z');
    H::basket($this->company, $this->bradford, T::BRADFORD_TILL, 500002, '2026-09-24T10:00:00Z', ['type' => 'refund']);
    $this->member = function (Company $company, CompanyRole $role = CompanyRole::Owner): User {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true]);

        return $user;
    };
    $this->owner = ($this->member)($this->company);
});

test('a small export downloads straight away with the filters applied and exact money', function () {
    $csv = $this->actingAs($this->owner)->get('/app/sales/export?status=refunds')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload('sales-2026-09-19-to-2026-09-25.csv')->streamedContent();
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", $csv)));

    expect($rows)->toHaveCount(2)
        ->and($rows[0][0])->toBe('Receipt')
        ->and($rows[1][0])->toBe('LDS-01-500002')
        ->and($rows[1][1])->toBe('Refund')
        ->and($rows[1][5])->toBe('Bradford')
        ->and($rows[1][15])->toBe('-3.70')
        ->and($rows[1][16])->toBe('Card')
        ->and(SalesExport::withoutCompanyScope()->count())->toBe(0);
});

test('a large export is queued, built as the business, and only its owner may download it', function () {
    $rows = [];
    for ($i = 1; $i <= SalesExport::STREAM_ROWS + 1; $i++) {
        $rows[] = [
            'id' => sprintf('01K5ZZ%020d', $i), 'company_id' => $this->company->id, 'branch_id' => T::LEEDS, 'register_id' => T::TILL_2,
            'user_id' => '', 'number' => $i, 'receipt_number' => sprintf('LDS-02-%06d', $i), 'type' => 'sale', 'status' => 'completed',
            'total' => '1.00', 'vat_total' => '0.17', 'completed_at' => '2026-09-23 10:00:00', 'trading_day' => '2026-09-23',
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('sales')->insert($chunk);
    }

    $this->actingAs($this->owner)->from('/app/sales')->get('/app/sales/export')->assertRedirect('/app/sales')->assertSessionHas('success');

    $export = SalesExport::withoutCompanyScope()->sole();
    expect($export->status)->toBe(ExportStatus::Ready)->and($export->row_count)->toBe(SalesExport::STREAM_ROWS + 3)
        ->and($export->user_id)->toBe($this->owner->id)->and($export->company_id)->toBe($this->company->id);

    $file = Storage::disk(SalesExport::DISK)->get((string) $export->path);
    expect(substr_count((string) $file, "\n"))->toBe(SalesExport::STREAM_ROWS + 4)->and($file)->toContain('LDS-01-500001');

    $this->actingAs($this->owner)->get('/app/sales')->assertInertia(fn (Assert $page) => $page
        ->where('exports.0.id', $export->id)->where('exports.0.status', 'ready')->where('exports.0.downloadable', true));
    $this->actingAs($this->owner)->get("/app/sales/exports/{$export->id}")->assertOk()->assertDownload('sales-2026-09-19-to-2026-09-25.csv');

    $colleague = ($this->member)($this->company, CompanyRole::Manager);
    $this->actingAs($colleague)->get("/app/sales/exports/{$export->id}")->assertNotFound();
    $this->actingAs($colleague)->get('/app/sales')->assertInertia(fn (Assert $page) => $page->has('exports', 0));

    $outsider = ($this->member)(Company::factory()->create());
    $this->actingAs($outsider)->get("/app/sales/exports/{$export->id}")->assertNotFound();

    $this->travel(8)->days();
    $this->actingAs($this->owner)->get("/app/sales/exports/{$export->id}")->assertNotFound();
});

test('a queued export keeps a one-shop user to their shop', function () {
    $user = User::factory()->create();
    $this->company->users()->attach($user->id, ['role' => CompanyRole::Manager->value, 'is_active' => true, 'branch_id' => T::BRADFORD]);
    $filters = new SaleFilters('2026-09-19', '2026-09-25', shop: T::BRADFORD, shopLocked: true);

    $export = app(CurrentCompany::class)->runAs($this->company, fn () => app(QueueSalesExport::class)->handle($this->company, $user->id, $filters));

    $file = (string) Storage::disk(SalesExport::DISK)->get((string) $export->fresh()?->path);
    expect($export->fresh()?->row_count)->toBe(1)->and($file)->toContain('LDS-01-500002')->not->toContain('LDS-01-500001');
});
