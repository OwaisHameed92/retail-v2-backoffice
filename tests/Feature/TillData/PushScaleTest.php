<?php

use App\Domain\Reporting\Actions\ProcessDirtyReportDays;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\EntityRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\TillData\TillFixtures;

/**
 * Scale (docs/scaling.md "Push path"): the work of a push is O(rows in the push), never O(the business's history).
 * The same push is applied to a new business and to one with thousands of stored sales, lines, payments, VAT rows,
 * customers and ledger rows. It must run the same statements with the same bindings, and no statement may read a big
 * till table through a `company_id`-only index prefix or a full scan (SQLite has no ANALYZE statistics, so its plan
 * does not depend on the data: a plan that reads the whole business does so at any size).
 */

/** Tables that grow with a business's history. */
const PUSH_SCALE_BIG_TABLES = ['sales', 's', 'sale_lines', 'l', 'sale_payments', 'p', 'sale_vats', 'v', 'customers', 'customer_transactions', 'stock_movements', 'sync_applied_changes', 'sync_conflicts'];

/**
 * @return array{company: Company, branch: Branch, till: Register}
 */
function pushScaleTenant(string $code): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->forCompany($company)->create(['code' => $code]);

    return ['company' => $company, 'branch' => $branch, 'till' => Register::factory()->forBranch($branch)->create(['code' => '01', 'is_main_till' => true])];
}

/**
 * Thousands of stored rows for the business, written straight to the tables (the push path is what is measured).
 *
 * @param  array{company: Company, branch: Branch, till: Register}  $tenant
 */
function pushScaleHistory(array $tenant, int $sales): void
{
    $id = fn (string $kind, int $n) => '01H0'.$kind.str_pad((string) $n, 21, '0', STR_PAD_LEFT);
    $base = ['company_id' => $tenant['company']->id, 'branch_id' => $tenant['branch']->id, 'register_id' => $tenant['till']->id, 'updated_at' => '2026-01-05 10:00:00', 'row_version' => 1];

    foreach (array_chunk(range(1, $sales), 500) as $chunk) {
        $rows = ['sales' => [], 'sale_lines' => [], 'sale_payments' => [], 'sale_vats' => [], 'customer_transactions' => [], 'customers' => []];

        foreach ($chunk as $n) {
            $sale = $id('S', $n);
            $rows['sales'][] = ['id' => $sale, ...$base, 'status' => 'completed', 'type' => 'sale', 'completed_at' => '2026-01-05 10:00:00', 'trading_day' => '2026-01-05', 'trading_hour' => 10];
            $rows['sale_lines'][] = ['id' => $id('L', $n * 2), ...$base, 'sale_id' => $sale];
            $rows['sale_lines'][] = ['id' => $id('L', $n * 2 + 1), ...$base, 'sale_id' => $sale];
            $rows['sale_payments'][] = ['id' => $id('P', $n), ...$base, 'sale_id' => $sale];
            $rows['sale_vats'][] = ['id' => $id('V', $n), ...$base, 'sale_id' => $sale];
            $rows['customer_transactions'][] = ['id' => $id('T', $n), ...array_diff_key($base, ['register_id' => 0]), 'customer_id' => $id('K', $n % 100), 'amount' => 1, 'points' => 1];

            if ($n % 5 === 0) {
                $rows['customers'][] = ['id' => $id('K', $n), 'company_id' => $tenant['company']->id, 'updated_at' => '2026-01-05 10:00:00', 'row_version' => 1];
            }
        }

        foreach ($rows as $table => $values) {
            DB::table($table)->insert($values);
        }
    }
}

/**
 * Two pushes for one till: the children of 40 sales first (stored with no till), then the measured one: the 40 sales
 * (their children get the till: backfill), a ledger row per sale, and one line deleted without a payload.
 *
 * @param  array{company: Company, branch: Branch, till: Register}  $tenant
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function pushScaleBatches(array $tenant): array
{
    $samples = collect(TillFixtures::sample('push-request.json'))->keyBy('entity');
    $ids = ['companyId' => $tenant['company']->id, 'branchId' => $tenant['branch']->id, 'registerId' => $tenant['till']->id];
    $code = $tenant['branch']->code;
    $id = fn (string $kind, int $n) => '01K7'.$kind.$code.str_pad((string) $n, 18, '0', STR_PAD_LEFT);
    $envelope = function (string $entity, array $payload, int $seq, array $overrides = []) use ($ids): array {
        $payload = [...$payload, 'companyId' => $ids['companyId']];

        foreach (['branchId', 'registerId'] as $scope) {
            if (($payload[$scope] ?? null) !== null) {
                $payload[$scope] = $ids[$scope];
            }
        }

        return TillFixtures::envelope($entity, $payload, $seq, $overrides);
    };
    $customer = [...TillFixtures::sample('entities/Customer.json'), 'id' => $id('K', 1)];
    $ledger = TillFixtures::sample('entities/CustomerTransaction.json');
    $first = [$envelope('Customer', $customer, 1)];
    $second = [];
    $seq = 2;

    for ($s = 1; $s <= 40; $s++) {
        foreach (['SaleLine', 'SalePayment', 'SaleVat'] as $n => $entity) {
            $first[] = $envelope($entity, [...$samples[$entity]['payload'], 'id' => $id(chr(65 + $n), $s), 'saleId' => $id('S', $s)], $seq++);
        }
    }

    for ($s = 1; $s <= 40; $s++) {
        $second[] = $envelope('Sale', [...$samples['Sale']['payload'], 'id' => $id('S', $s), 'number' => $s, 'customerId' => $customer['id']], $seq++);
        $second[] = $envelope('CustomerTransaction', [...$ledger, 'id' => $id('T', $s), 'customerId' => $customer['id'], 'saleId' => $id('S', $s)], $seq++);
    }

    $gone = $first[1];
    $second[] = [...$gone, 'seq' => $seq, 'op' => 'D', 'version' => 2, 'payload' => null, 'at' => '2026-09-23T12:00:00Z', 'key' => "SaleLine:{$gone['entityId']}:2"];

    return [$first, $second];
}

/**
 * @return list<array{sql: string, bindings: array<mixed>}>
 */
function pushScaleCapture(callable $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $queries = array_map(fn (array $q) => ['sql' => $q['query'], 'bindings' => $q['bindings']], DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

/**
 * Plan lines that read a big table through its `company_id` alone, or all of it.
 *
 * @param  list<array{sql: string, bindings: array<mixed>}>  $queries
 * @return list<string>
 */
function pushScaleWholeBusinessReads(array $queries): array
{
    $bad = [];

    foreach ($queries as $query) {
        if (preg_match('/^\s*(select|update|delete)\b/i', $query['sql']) !== 1) {
            continue;
        }

        foreach (DB::select('explain query plan '.$query['sql'], $query['bindings']) as $line) {
            $detail = (string) $line->detail;

            if (preg_match('/^(SCAN|SEARCH) (\S+)(?: AS \S+)?(.*)$/', $detail, $m) === 1 && in_array($m[2], PUSH_SCALE_BIG_TABLES, true)
                && ($m[1] === 'SCAN' || preg_match('/\(company_id=\?\)$/', $m[3]) === 1)) {
                $bad[] = $detail.'  <=  '.substr($query['sql'], 0, 160);
            }
        }
    }

    return $bad;
}

it('does the same indexed work for a push whatever the business\'s history', function () {
    Queue::fake();
    $new = pushScaleTenant('NEW');
    $busy = pushScaleTenant('BSY');
    pushScaleHistory($busy, 3000);
    $runs = [];

    foreach (['new' => $new, 'busy' => $busy] as $name => $tenant) {
        [$first, $second] = pushScaleBatches($tenant);
        expect(TillFixtures::apply($tenant['company'], $tenant['branch'], $first)->accepted)->toBe(count($first))
            ->and(DB::table('sale_lines')->where('company_id', $tenant['company']->id)->whereNull('register_id')->count())->toBe(40);

        $runs[$name] = pushScaleCapture(fn () => expect(TillFixtures::apply($tenant['company'], $tenant['branch'], $second)->accepted)->toBe(count($second)));

        // Behaviour kept: the backfill gave the children their sale's till, the ledger set the balance.
        expect(DB::table('sale_lines')->where('company_id', $tenant['company']->id)->whereNull('register_id')->count())->toBe(0)
            ->and(DB::table('sale_payments')->where('company_id', $tenant['company']->id)->where('register_id', $tenant['till']->id)->count())->toBeGreaterThanOrEqual(40)
            ->and(DB::table('customers')->where('company_id', $tenant['company']->id)->where('name', 'Aisha Rahman')->value('balance'))->toEqual(336);
    }

    $shape = fn (array $queries) => [count($queries), array_sum(array_map(fn ($q) => count($q['bindings']), $queries))];

    expect(DB::table('sale_lines')->where('company_id', $busy['company']->id)->count())->toBe(6000 + 40)
        ->and($shape($runs['busy']))->toBe($shape($runs['new']))
        ->and(array_column($runs['busy'], 'sql'))->toBe(array_column($runs['new'], 'sql'))
        ->and(pushScaleWholeBusinessReads($runs['busy']))->toBe([]);
});

it('rebuilds a pushed shop-day without reading the business\'s other days', function () {
    Queue::fake();
    $busy = pushScaleTenant('BSY');
    pushScaleHistory($busy, 1000);
    [$first, $second] = pushScaleBatches($busy);
    TillFixtures::apply($busy['company'], $busy['branch'], [...$first, ...$second]);

    $queries = pushScaleCapture(fn () => app(ProcessDirtyReportDays::class)->handle($busy['company']->id));

    expect($queries)->not->toBeEmpty()
        ->and(pushScaleWholeBusinessReads($queries))->toBe([]);
});

it('indexes the parent column of every child table (the backfill and report lookups use it)', function () {
    $missing = [];

    foreach (EntityRegistry::names() as $entity) {
        $def = EntityRegistry::get($entity);

        if ($def->parent === null || $def->tenancy) {
            continue;
        }

        $leading = collect(DB::select("select name from pragma_index_list('{$def->table}')"))
            ->map(fn ($index) => DB::selectOne("select name from pragma_index_info('{$index->name}') where seqno = 0")?->name)
            ->all();

        if (! in_array($def->parent['column'], $leading, true)) {
            $missing[] = "{$def->table}.{$def->parent['column']}";
        }
    }

    expect($missing)->toBe([]);
});
