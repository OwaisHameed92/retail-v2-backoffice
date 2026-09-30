<?php

use App\Domain\Reporting\Actions\GenerateDemoSales;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.2: `demo:sales` fills the demo tenants with till-shaped sales through the real push path and rebuilds
 * their reporting tables. Runs on the in-memory test database only.
 */

// Wednesday 30 Sept 2026, 15:30 London (BST).
const DEMO_NOW = '2026-09-30 14:30:00';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(DEMO_NOW, 'UTC'));
    $this->khan = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $leeds = Branch::factory()->forCompany($this->khan)->create(['code' => 'LDS', 'name' => 'Leeds']);
    $bradford = Branch::factory()->forCompany($this->khan)->create(['code' => 'BFD', 'name' => 'Bradford']);
    Register::factory()->forBranch($leeds)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
    Register::factory()->forBranch($leeds)->create(['code' => '02', 'name' => 'Till 2']);
    Register::factory()->forBranch($bradford)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
    $this->leeds = $leeds;
});

function demoCount(string $table, string $companyId): int
{
    return DB::table($table)->where('company_id', $companyId)->count();
}

it('fills the demo tenant with till-shaped sales and rebuilds the reporting tables to match', function () {
    $this->artisan('demo:sales', ['--days' => 3])
        ->expectsOutputToContain('Khan Mini Mart')
        ->expectsOutputToContain('Reporting tables rebuilt')
        ->assertSuccessful();

    $sales = DB::table('sales')->where('company_id', $this->khan->id);
    expect((clone $sales)->count())->toBeGreaterThan(600)
        ->and((clone $sales)->whereNull('trading_day')->count())->toBe(0)
        ->and((clone $sales)->where('trading_day', '>', '2026-09-30')->count())->toBe(0)
        ->and((clone $sales)->where('completed_at', '>', CarbonImmutable::now()->format('Y-m-d H:i:s'))->count())->toBe(0)
        ->and((clone $sales)->distinct()->pluck('trading_day')->map(fn ($d) => substr((string) $d, 0, 10))->sort()->values()->all())->toBe(['2026-09-28', '2026-09-29', '2026-09-30'])
        ->and((clone $sales)->min('trading_hour'))->toBeGreaterThanOrEqual(7)
        ->and((clone $sales)->max('trading_hour'))->toBeLessThanOrEqual(21)
        ->and((clone $sales)->where('type', 'refund')->count())->toBeGreaterThan(0)
        ->and((clone $sales)->where('status', 'voided')->count())->toBeGreaterThan(0)
        ->and(DB::table('sale_vats')->where('company_id', $this->khan->id)->distinct()->pluck('code')->sort()->values()->all())->toBe(['R', 'S', 'Z'])
        ->and(DB::table('sale_payments')->where('company_id', $this->khan->id)->distinct()->pluck('payment_type_name')->sort()->values()->all())->toBe(['Card', 'Cash'])
        ->and(DB::table('sales')->whereNotIn('register_id', Register::withoutCompanyScope()->pluck('id'))->count())->toBe(0);

    // The rebuilt tables equal a fresh computation from the raw rows, and nothing is left queued.
    $this->artisan('reports:check', ['--company' => [$this->khan->id]])->assertSuccessful();
    expect(demoCount(ReportTables::DIRTY_DAYS, $this->khan->id))->toBe(0);

    // Takings = Σ Sale.total of counted sales = Σ tenders; VAT bands add up to the gross.
    $scope = ReportScope::admin($this->khan->id, '2026-09-28', '2026-09-30');
    $totals = app(SalesReport::class)->totals($scope);
    $raw = DB::table('sales')->where('company_id', $this->khan->id)->where('status', 'completed')->selectRaw('SUM(ROUND(total * 100)) as p')->value('p');
    $tenders = collect(app(TenderReport::class)->byPaymentType($scope))->sum(fn ($t) => (int) round((float) $t->amount * 100));
    $vatGross = collect(app(VatReport::class)->byRate($scope))->sum(fn ($v) => (int) round((float) $v->gross * 100));

    expect((int) round((float) $totals->takings * 100))->toBe((int) $raw)
        ->and($tenders)->toBe((int) $raw)
        ->and($vatGross)->toBe((int) round((float) $totals->gross * 100))
        ->and($totals->refundCount)->toBeGreaterThan(0)
        ->and($totals->voidCount)->toBeGreaterThan(0)
        ->and((float) $totals->promo)->toBeGreaterThan(0.0);
});

it('stores nothing twice when run again, and --fresh replaces only the demo sales', function () {
    [, $leeds] = [null, $this->leeds];
    $till = Register::withoutCompanyScope()->where('branch_id', $leeds->id)->where('is_main_till', true)->firstOrFail();
    $basket = ReportFixtures::basket('990001', '2026-09-29T12:00:00Z', 1, ['register' => $till->id, 'branch' => $leeds->id]);
    ReportFixtures::push($this->khan, $leeds, json_decode(str_replace(TillFixtures::COMPANY, $this->khan->id, (string) json_encode($basket)), true));

    $this->artisan('demo:sales', ['--company' => 'Khan Mini Mart', '--days' => 2])->assertSuccessful();
    $first = [demoCount('sales', $this->khan->id), demoCount('sale_lines', $this->khan->id), ReportFixtures::snapshot($this->khan->id)];

    $this->artisan('demo:sales', ['--company' => $this->khan->id, '--days' => 2])->assertSuccessful();
    expect([demoCount('sales', $this->khan->id), demoCount('sale_lines', $this->khan->id)])->toBe([$first[0], $first[1]]);

    $this->artisan('demo:sales', ['--company' => $this->khan->id, '--days' => 1, '--fresh' => true])
        ->expectsOutputToContain('earlier demo sales removed')
        ->assertSuccessful();

    $left = DB::table('sales')->where('company_id', $this->khan->id);
    expect((clone $left)->where('trading_day', '2026-09-29')->pluck('id')->all())->toBe([ReportFixtures::saleId('990001')])
        ->and((clone $left)->where('trading_day', '2026-09-30')->count())->toBeGreaterThan(100)
        ->and(ReportFixtures::daily($leeds->id, $till->id, '2026-09-29'))->toMatchArray(['txn_count' => '1', 'net' => '4.53']);

    $this->artisan('reports:check', ['--company' => [$this->khan->id]])->assertSuccessful();
});

it('refuses to run in production and writes nothing', function () {
    $this->app['env'] = 'production';

    $this->artisan('demo:sales')->expectsOutputToContain('never runs in production')->assertFailed();

    expect(fn () => app(GenerateDemoSales::class)->handle($this->khan, 1))->toThrow(RuntimeException::class)
        ->and(DB::table('sales')->count())->toBe(0);
});

it('says what is wrong when there is nothing to fill or the days are invalid', function () {
    $this->artisan('demo:sales', ['--company' => 'Nobody Stores'])->expectsOutputToContain('No business matches')->assertFailed();
    $this->artisan('demo:sales', ['--days' => 0])->expectsOutputToContain('--days must be')->assertExitCode(2);

    $this->khan->forceFill(['name' => 'Renamed'])->save();
    $this->artisan('demo:sales')->expectsOutputToContain('None of the demo tenants')->assertFailed();

    expect(DB::table('sales')->count())->toBe(0);
});
