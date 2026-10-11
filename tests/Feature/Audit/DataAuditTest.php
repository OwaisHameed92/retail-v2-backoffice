<?php

use App\Domain\Catalogue\Jobs\ApplyProductImportJob;
use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\MasterCatalogue\Jobs\MasterImportJob;
use App\Domain\Notifications\Actions\SendDigests;
use App\Domain\Privacy\Actions\SavePrivacySettings;
use App\Domain\Purchasing\Jobs\ReadInvoiceJob;
use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Dashboard\DashboardKpis;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Jobs\ProcessDirtyReportDaysJob;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\DirtyDays;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Sales\Jobs\BuildSalesExportJob;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Reporting\ReportFixtures as R;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Data audit 2026-10-11 (docs/audit/2026-10-11-data.md). Each `DATA-n` test pins the CURRENT behaviour of a finding so
 * the suite stays green: when the finding is fixed, the test fails and its expectation is flipped to the fixed one.
 * The `fine:` tests prove what the audit found correct (report figures against raw till rows, DST, PK day).
 */

/**
 * A basket from the §6 sample with its own type/status/time; `cash` pays £10 cash with change.
 *
 * @param  array<string, mixed>  $o
 * @return list<array<string, mixed>>
 */
function auditBasket(string $number, string $completedAt, int $seq, array $o = []): array
{
    $rows = R::basket($number, $completedAt, $seq, $o);

    foreach ($rows as &$row) {
        if ($row['entity'] === 'Sale' && ($o['status'] ?? 'completed') === 'voided') {
            $row['payload']['updatedAt'] = $completedAt;
        }

        if ($row['entity'] === 'SalePayment' && ($o['cash'] ?? false)) {
            $row['payload']['paymentTypeId'] = '01K5T0Q8C4000000000000T002';
            $row['payload']['paymentTypeName'] = 'Cash';
            $row['payload']['amount'] = 10.0;
            $row['payload']['changeGiven'] = 4.85;
        }
    }

    return $rows;
}

/**
 * Raw sums of counted sales of a shop whose `completed_at` falls in a UTC window, straight from the till rows.
 *
 * @return array{gross: string, takings: string, tenders: string, txn: int, refunds: int}
 */
function auditRaw(string $companyId, string $branchId, string $start, string $end): array
{
    $counted = fn () => DB::table('sales')->where('company_id', $companyId)->where('branch_id', $branchId)
        ->where('status', 'completed')->whereNotNull('completed_at')->whereNull('deleted_at')
        ->whereIn('type', ['sale', 'refund', 'exchange', 'deposit'])
        ->where('completed_at', '>=', $start)->where('completed_at', '<', $end);
    $ids = $counted()->pluck('id')->all();

    return [
        'gross' => Money::sum(DB::table('sale_vats')->whereIn('sale_id', $ids)->whereNull('deleted_at')->pluck('gross')),
        'takings' => Money::sum($counted()->pluck('total')),
        'tenders' => Money::sum(DB::table('sale_payments')->whereIn('sale_id', $ids)->whereNull('deleted_at')->whereIn('status', ['approved', 'recovered'])
            ->get()->map(fn ($p) => Money::sub(Money::sub($p->amount, $p->cashback ?? 0), $p->change_given ?? 0))),
        'txn' => $counted()->whereNotIn('type', ['refund', 'deposit'])->count(),
        'refunds' => $counted()->where('type', 'refund')->count(),
    ];
}

/** Sales on and around the UK clock change (Sunday 25 Oct 2026, 25 hours long), mixed types and statuses. */
function auditClockChangeDay(Company $company, Branch $leeds): void
{
    R::push($company, $leeds, [
        ...auditBasket('900001', '2026-10-24T18:59:59Z', 10),                       // PK: 23:59:59 on the 24th
        ...auditBasket('900002', '2026-10-24T19:00:00Z', 20, ['cash' => true]),     // PK: 00:00 on the 25th
        ...auditBasket('900003', '2026-10-24T22:59:59Z', 30),                       // GB: 23:59:59 BST on the 24th
        ...auditBasket('900004', '2026-10-24T23:00:00Z', 40),                       // GB: 00:00 BST on the 25th
        ...auditBasket('900005', '2026-10-25T00:30:00Z', 50, ['cash' => true]),     // GB: first 01:30 (BST)
        ...auditBasket('900006', '2026-10-25T01:30:00Z', 60, ['type' => 'refund']), // GB: second 01:30 (GMT)
        ...auditBasket('900007', '2026-10-25T10:00:00Z', 70, ['status' => 'voided']),
        ...auditBasket('900008', '2026-10-25T11:00:00Z', 80, ['type' => 'training']),
        ...auditBasket('900009', '2026-10-25T12:00:00Z', 90, ['type' => 'quote']),
        ...auditBasket('900010', '2026-10-25T13:00:00Z', 100, ['status' => 'open']),
        ...auditBasket('900011', '2026-10-25T18:59:59Z', 110),                      // PK: 23:59:59 on the 25th
        ...auditBasket('900012', '2026-10-25T23:59:59Z', 120),                      // GB: 23:59:59 GMT on the 25th
        ...auditBasket('900013', '2026-10-26T00:00:00Z', 130),                      // GB: 00:00 GMT on the 26th
    ]);
}

test('fine: the 25 Oct 2026 report figures equal the raw till rows of the local day, UK and Pakistan', function (string $zone) {
    config(['reporting.timezone' => $zone]);
    [$company, $leeds] = T::tenant();
    auditClockChangeDay($company, $leeds);

    [$start, $end] = TradingDay::window('2026-10-25');
    $raw = auditRaw($company->id, T::LEEDS, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));

    app(CurrentCompany::class)->runAs($company, function () use ($raw) {
        $scope = ReportScope::tenant('2026-10-25', '2026-10-25', [T::LEEDS]);
        $totals = app(SalesReport::class)->totals($scope);
        $tenders = Money::sum(array_map(fn ($t) => $t->amount, app(TenderReport::class)->byPaymentType($scope)));
        $hours = app(SalesReport::class)->byHour($scope);

        expect([$totals->gross, $totals->takings, $tenders, $totals->transactions, $totals->refundCount])
            ->toBe([$raw['gross'], $raw['takings'], $raw['tenders'], $raw['txn'], $raw['refunds']])
            ->and($totals->voidCount)->toBe(1)
            ->and(array_sum(array_map(fn ($h) => $h->transactions, $hours)))->toBe($raw['txn']);
    });

    // UK: the 25-hour day holds 00:00 BST … 23:59:59 GMT, both 01:30s in hour 1. PK: no clock change.
    expect($raw['txn'] + $raw['refunds'])->toBe($zone === 'Europe/London' ? 5 : 6);
})->with(['UK' => 'Europe/London', 'Pakistan' => 'Asia/Karachi']);

test('fine: a full rebuild changes nothing after incremental pushes (reports:check is clean)', function () {
    [$company, $leeds] = T::tenant();
    auditClockChangeDay($company, $leeds);
    $before = R::snapshot($company->id);

    $this->artisan('reports:rebuild', ['--company' => [$company->id]])->assertSuccessful();
    $this->artisan('reports:check', ['--company' => [$company->id]])->assertSuccessful();

    expect(R::snapshot($company->id))->toBe($before);
});

test('DATA-1: queue retry_after (90 s) is shorter than the long jobs\' timeouts, so a slow job is handed to a second worker while the first still runs', function () {
    $timeout = fn (string $job) => (new ReflectionClass($job))->getDefaultProperties()['timeout'] ?? null;

    foreach (['redis', 'database'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBe(90);
    }

    expect($timeout(BuildSalesExportJob::class))->toBe(1800)
        ->and($timeout(ApplyProductImportJob::class))->toBe(300)
        ->and($timeout(MasterImportJob::class))->toBe(300)
        ->and($timeout(ReadInvoiceJob::class))->toBe(300)
        // No own timeout: the worker's default --timeout=60 applies (deploy/supervisor has none).
        ->and($timeout(ProcessDirtyReportDaysJob::class))->toBeNull()
        ->and((string) file_get_contents(base_path('deploy/supervisor/retail-v2-worker.conf')))->not->toContain('--timeout');
});

test('DATA-2: alerts:digest stops at the first business that fails; every business after it gets no digest that day', function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 07:00', 'Europe/London'));
    [$first] = T::tenant();                                        // id 01K5T… sorts first
    $second = Company::factory()->create(['name' => 'Patel News']);
    $secondShop = Branch::factory()->forCompany($second)->create(['code' => 'PTL', 'name' => 'Patel']);
    $ownerOne = C::member($first, CompanyRole::Owner);
    $ownerTwo = C::member($second, CompanyRole::Owner);
    S::product($first->id, S::id('PA', 1), 'Cola');
    S::line($first->id, T::LEEDS, S::id('PA', 1), '0');
    S::product($second->id, S::id('PB', 1), 'Crisps');
    S::line($second->id, $secondShop->id, S::id('PB', 1), '0');

    // A transient error while handling the first business only (a deadlock, a lost connection, a bad row).
    $failed = false;
    DB::listen(function (QueryExecuted $q) use ($first, &$failed) {
        if (! $failed && str_contains($q->sql, 'alert_dispatches') && in_array($first->id, $q->bindings, true)) {
            $failed = true;

            throw new RuntimeException('Simulated failure for one business');
        }
    });

    expect(fn () => app(SendDigests::class)->handle())->toThrow(RuntimeException::class);

    Mail::assertNotQueued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($ownerTwo->email));
    Mail::assertNotQueued(OwnerDigestMail::class, fn (OwnerDigestMail $m) => $m->hasTo($ownerOne->email));
});

test('DATA-3: privacy:retention takes the same 500 oldest-id customers every day; when they all owe money nobody else is ever anonymised', function () {
    $this->travelTo('2026-10-24 12:00:00');
    [$company] = T::tenant();
    $rows = [];
    $ledger = [];

    for ($i = 0; $i < 500; $i++) {
        $id = '00000000000000000000'.sprintf('%06d', $i);   // sorts before every ULID
        $rows[] = ['id' => $id, 'company_id' => $company->id, 'name' => "Owing {$i}", 'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2023-01-01 00:00:00'];
        $ledger[] = ['id' => 'CT'.substr($id, 2), 'company_id' => $company->id, 'branch_id' => T::LEEDS, 'customer_id' => $id, 'type' => 'charge', 'amount' => '5.00',
            'points' => 0, 'sale_id' => '', 'balance_after' => '5.00', 'points_after' => 0, 'user_id' => '', 'note' => '', 'at' => '2023-01-01 00:00:00', 'row_version' => 1,
            'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2023-01-01 00:00:00'];
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('customers')->insert($chunk);
    }

    foreach (array_chunk($ledger, 100) as $chunk) {
        DB::table('customer_transactions')->insert($chunk);
    }

    $settled = app(SaveCustomer::class)->handle($company, null, ['name' => 'Olive Old', 'email' => 'olive@example.co.uk']);
    DB::table('customers')->where('id', $settled->id)->update(['updated_at' => '2023-01-01 00:00:00', 'created_at' => '2023-01-01 00:00:00']);
    app(SavePrivacySettings::class)->handle($company, 24, true, null);

    foreach (range(1, 3) as $day) {   // three daily runs
        $this->artisan('privacy:retention', ['--apply' => true])->assertSuccessful();
    }

    expect(Customer::withoutCompanyScope()->find($settled->id)->anonymised_at)->toBeNull()
        ->and(Customer::withoutCompanyScope()->whereNotNull('anonymised_at')->count())->toBe(0);
});

test('DATA-4: re-marking a dirty day moves marked_at forward, so the sweep never re-queues a shop that keeps pushing', function () {
    Queue::fake();
    [$company] = T::tenant();
    $this->travelTo('2026-10-11 09:00:00');
    DirtyDays::mark($company->id, ['x' => ['branch' => T::LEEDS, 'day' => '2026-10-11']]);

    // The rebuild job for that push was lost; the till keeps pushing every minute (each push re-marks the day).
    foreach (range(1, 10) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 09:00:00', 'UTC')->addMinutes($minute));
        DirtyDays::mark($company->id, ['x' => ['branch' => T::LEEDS, 'day' => '2026-10-11']]);
        $this->artisan('reports:process-dirty')->assertSuccessful();
    }

    Queue::assertNotPushed(ProcessDirtyReportDaysJob::class);
    expect(DB::table(ReportTables::DIRTY_DAYS)->value('marked_at'))->toBe('2026-10-11 09:10:00');
});

test('DATA-5: the daily prunes on AI usage, anomalies, morning summaries, AI actions and conversations have no index on their date column', function () {
    $indexed = fn (string $table, string $column) => collect(Schema::getIndexes($table))
        ->contains(fn (array $i) => ($i['columns'][0] ?? null) === $column);

    expect($indexed('ai_usage', 'created_at'))->toBeFalse()
        ->and($indexed('anomalies', 'created_at'))->toBeFalse()
        ->and($indexed('morning_summaries', 'created_at'))->toBeFalse()
        ->and($indexed('ai_pending_actions', 'created_at'))->toBeFalse()
        ->and($indexed('ai_conversations', 'updated_at'))->toBeFalse()
        // For contrast, the prunes that are indexed.
        ->and($indexed('email_logs', 'created_at'))->toBeTrue()
        ->and($indexed('alert_notifications', 'created_at'))->toBeTrue();
});

test('DATA-6: "Today" compares up to now against the WHOLE current hour of the compare day, so the morning tiles read worse than they are', function () {
    [$company, $leeds] = T::tenant();
    // Yesterday (Thu 24 Sep 2026, BST): £5.15 at 09:30 and £5.15 at 10:50. Today: £5.15 at 09:30; it is now 10:05.
    R::push($company, $leeds, [
        ...R::basket('910001', '2026-09-24T08:30:00Z', 10),
        ...R::basket('910002', '2026-09-24T09:50:00Z', 20),
        ...R::basket('910003', '2026-09-25T08:30:00Z', 30),
    ]);
    $now = CarbonImmutable::parse('2026-09-25 10:05', 'Europe/London');
    $filters = BusinessDashboardFilters::resolve($company->id, TradingPeriod::Today, null, null, TradingCompare::PreviousPeriod, now: $now);

    $kpis = app(CurrentCompany::class)->runAs($company, fn () => app(DashboardKpis::class)->build($filters, [], [], []));

    // Up to 10:05 yesterday took £5.15, the same as today (0 %). The tile shows -50 %: yesterday's 10:50 sale counts.
    expect($kpis['headline']['gross']['value'])->toBe('5.15')
        ->and($kpis['headline']['gross']['previous'])->toBe('10.30')
        ->and($kpis['headline']['gross']['change'])->toBe('-50.0');
});
