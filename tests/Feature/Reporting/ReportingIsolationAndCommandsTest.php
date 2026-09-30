<?php

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Jobs\ProcessDirtyReportDaysJob;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\DirtyDays;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    $this->otherTill = Register::factory()->forBranch($this->otherShop)->create(['code' => '01', 'is_main_till' => true]);

    $basket = ReportFixtures::basket('990001', '2026-09-23T12:00:00Z', 1, ['register' => $this->otherTill->id, 'branch' => $this->otherShop->id]);
    $basket = json_decode(str_replace(TillFixtures::COMPANY, $this->other->id, (string) json_encode($basket)), true);
    ReportFixtures::push($this->other, $this->otherShop, $basket);
});

it('keeps each business to its own figures, even when it names another business\'s shop', function () {
    $mine = app(CurrentCompany::class)->runAs($this->company, fn () => [
        app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23'))->net,
        app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23', [$this->otherShop->id]))->transactions,
        RptSalesDaily::query()->count(),
    ]);

    $theirs = app(CurrentCompany::class)->runAs($this->other, fn () => [
        app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23'))->net,
        app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]))->transactions,
    ]);

    expect($mine)->toBe(['4.53', 0, 1])
        ->and($theirs)->toBe(['4.53', 0])
        ->and(DB::table(ReportTables::SALES_DAILY)->count())->toBe(2);
});

it('fails closed without a current business, and only the admin scope reads across businesses', function () {
    expect(fn () => RptSalesDaily::query()->count())->toThrow(MissingCurrentCompany::class)
        ->and(fn () => ReportScope::tenant('2026-09-23', '2026-09-23'))->toThrow(MissingCurrentCompany::class);

    $all = app(SalesReport::class)->totals(ReportScope::admin(null, '2026-09-23', '2026-09-23'));
    $one = app(SalesReport::class)->totals(ReportScope::admin($this->other->id, '2026-09-23', '2026-09-23'));
    $byCompany = app(SalesReport::class)->byCompany(ReportScope::admin(null, '2026-09-23', '2026-09-23'));

    expect([$all->net, $all->transactions])->toBe(['9.06', 2])
        ->and($one->net)->toBe('4.53')
        ->and(array_map(fn ($g) => $g->label, $byCompany))->toEqualCanonicalizing(['Kirkgate Convenience', 'Other Stores'])
        ->and(fn () => app(CurrentCompany::class)->runAs($this->company, fn () => app(SalesReport::class)->byCompany(ReportScope::tenant('2026-09-23', '2026-09-23'))))
        ->toThrow(InvalidArgumentException::class);
});

it('rebuilds one business only and leaves the other untouched', function () {
    DB::table(ReportTables::SALES_DAILY)->update(['net' => 1]);

    $this->artisan('reports:rebuild', ['--company' => [$this->company->id], '--from' => '2026-09-01', '--to' => '2026-09-30'])
        ->expectsOutputToContain('Rebuilt 1 shop-days')
        ->assertSuccessful();

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23')['net'])->toBe('4.53')
        ->and(ReportFixtures::daily($this->otherShop->id, $this->otherTill->id, '2026-09-23')['net'])->toBe('1.00');
});

it('reports:check finds a tampered or stray row, exits 1, and --fix repairs it', function () {
    $this->artisan('reports:check')->assertSuccessful();

    DB::table(ReportTables::VAT_DAILY)->where('company_id', $this->company->id)->update(['vat' => 9]);
    DB::table(ReportTables::SALES_DAILY)->insert([
        'company_id' => $this->company->id, 'branch_id' => TillFixtures::LEEDS, 'trading_day' => '2026-09-01',
        'register_id' => TillFixtures::TILL_1, 'net' => 10, 'rebuilt_at' => '2026-09-01 00:00:00',
    ]);

    $this->artisan('reports:check')->expectsOutputToContain('rows differ')->assertFailed();
    $this->artisan('reports:check', ['--fix' => true])->assertFailed();
    $this->artisan('reports:check')->assertSuccessful();

    expect(DB::table(ReportTables::SALES_DAILY)->where('trading_day', '2026-09-01')->count())->toBe(0);
});

it('stamps sales stored before 3.1 when rebuilding', function () {
    DB::table('sales')->update(['trading_day' => null, 'trading_hour' => null]);
    DB::table(ReportTables::SALES_DAILY)->delete();

    $this->artisan('reports:check')->expectsOutputToContain('no trading day')->assertFailed();
    $this->artisan('reports:rebuild')->expectsOutputToContain('Trading day stamped on 2 older sales')->assertSuccessful();

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23')['net'])->toBe('4.53');
});

it('queues one rebuild per push that applied sale rows, and none for a replay or other rows', function () {
    Queue::fake();

    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000701', '2026-09-24T10:00:00Z', 700));
    Queue::assertPushed(ProcessDirtyReportDaysJob::class, 1);
    expect(DB::table(ReportTables::DIRTY_DAYS)->where('trading_day', '2026-09-24')->count())->toBe(1);

    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000701', '2026-09-24T10:00:00Z', 700));
    TillFixtures::apply($this->company, $this->leeds, array_slice(TillFixtures::sample('push-request.json'), 6, 3));
    Queue::assertPushed(ProcessDirtyReportDaysJob::class, 1);
});

it('the sweep picks up days whose job was lost; the job rebuilds them', function () {
    DirtyDays::mark($this->company->id, [TillFixtures::LEEDS.'|2026-09-23' => ['branch' => TillFixtures::LEEDS, 'day' => '2026-09-23']]);
    DB::table(ReportTables::DIRTY_DAYS)->update(['marked_at' => now('UTC')->subMinutes(10)->format('Y-m-d H:i:s')]);
    DB::table(ReportTables::SALES_DAILY)->delete();

    $this->artisan('reports:process-dirty')->expectsOutputToContain('Queued 1 businesses')->assertSuccessful();

    expect(DB::table(ReportTables::DIRTY_DAYS)->count())->toBe(0)
        ->and(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23')['net'])->toBe('4.53');
});

it('rejects a bad date', function () {
    $this->artisan('reports:rebuild', ['--from' => '23/09/2026'])->assertExitCode(2);
});
