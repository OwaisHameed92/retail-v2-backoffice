<?php

use App\Domain\Demo\Actions\SeedDemoBusiness;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Actions\ApplySyncChanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * `demo:seed`: a complete demo business through the real push path. Runs on the in-memory test database only.
 */

/** One table per area the command fills. */
const DEMO_SEED_AREAS = [
    'products', 'product_barcodes', 'departments', 'categories', 'vat_rates', 'suppliers', 'product_suppliers', 'branch_products',
    'stock_movements', 'fifo_stock_layers', 'stock_layers', 'stock_takes', 'stock_take_lines', 'purchase_orders', 'goods_receipts',
    'goods_receipt_lines', 'supplier_invoices', 'supplier_credit_notes', 'purchase_returns', 'supplier_payments', 'customers', 'consents',
    'customer_transactions', 'till_users', 'till_roles', 'till_user_branches', 'rota_shifts', 'clock_events', 'timesheet_approvals',
    'wage_rates', 'training_records', 'shifts', 'shift_tenders', 'cash_counts', 'cash_movements', 'z_reports', 'cash_office_bankings',
    'card_settlements', 'promotion_rules', 'promotion_items', 'promotion_redemptions', 'news_titles', 'news_deliveries', 'age_refusals',
    'incident_reports', 'diary_check_records', 'compliance_licences', 'product_recalls', 'journal_entries', 'journal_lines',
    'ledger_accounts', 'hardware_checks', 'till_health', 'sales', 'sale_lines', 'rpt_sales_daily',
];

beforeEach(function () {
    // Thursday 1 Oct 2026, 16:00 London (BST).
    $this->travelTo(CarbonImmutable::parse('2026-10-01 15:00:00', 'UTC'));
    $this->shop = Company::factory()->create(['name' => 'Corner Demo Stores']);
    $this->branch = Branch::factory()->forCompany($this->shop)->create(['code' => 'CDS', 'name' => 'Leeds']);
    Register::factory()->forBranch($this->branch)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);

    $this->other = Company::factory()->create(['name' => 'Someone Else Ltd']);
    $otherBranch = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'York']);
    Register::factory()->forBranch($otherBranch)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
    $this->otherSupplier = tillSupplier($this->other, $otherBranch, 'Their own supplier');
    $this->ownSupplier = tillSupplier($this->shop, $this->branch, 'A supplier the till made');
});

/** A row a till pushed itself (not the demo). */
function tillSupplier(Company $company, Branch $branch, string $name): string
{
    $id = Ulid::new();
    $at = '2026-09-01T09:00:00Z';
    app(ApplySyncChanges::class)->handle($company, $branch, [[
        'seq' => 1, 'entity' => 'Supplier', 'entityId' => $id, 'op' => 'I', 'version' => 1, 'companyId' => $company->id, 'branchId' => '',
        'registerId' => '', 'at' => $at, 'payload' => ['id' => $id, 'companyId' => $company->id, 'name' => $name, 'code' => 'OWN', 'isActive' => true, 'createdAt' => $at, 'updatedAt' => $at, 'rowVersion' => 1],
    ]]);

    return $id;
}

/**
 * @return array<string, int>
 */
function demoSeedCounts(string $companyId): array
{
    return collect(DEMO_SEED_AREAS)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->where('company_id', $companyId)->count()])->all();
}

it('fills every area of a business through the push path, repeatably, and never touches another business', function () {
    $otherBefore = demoSeedCounts($this->other->id);

    $this->artisan('demo:seed', ['--company' => 'Corner Demo Stores', '--days' => 2])
        ->expectsOutputToContain('Corner Demo Stores')
        ->expectsOutputToContain('Reporting tables rebuilt')
        ->assertSuccessful();

    $counts = demoSeedCounts($this->shop->id);
    expect(array_keys(array_filter($counts, fn (int $n) => $n === 0)))->toBe([])
        ->and($counts['products'])->toBeGreaterThan(580)
        ->and(demoSeedCounts($this->other->id))->toBe($otherBefore)
        ->and(DB::table('suppliers')->where('id', $this->otherSupplier)->value('name'))->toBe('Their own supplier')
        ->and(DB::table('sync_conflicts')->count())->toBe(0);

    // Sale lines name real products; barcodes are valid EAN-13; VAT at 20, 5 and 0; journals balance; some stock low or negative.
    $id = $this->shop->id;
    expect(DB::table('sale_lines')->where('company_id', $id)->whereNotIn('product_id', DB::table('products')->where('company_id', $id)->select('id'))->count())->toBe(0)
        ->and(DB::table('product_barcodes')->where('company_id', $id)->pluck('barcode')->reject(fn ($b) => DemoProducts::isValidEan13((string) $b))->all())->toBe([])
        ->and(DB::table('vat_rates')->where('company_id', $id)->pluck('percentage')->map(fn ($p) => (int) $p)->sort()->values()->all())->toBe([0, 5, 20])
        ->and((int) DB::table('journal_lines')->where('company_id', $id)->selectRaw('SUM(ROUND(debit * 100)) - SUM(ROUND(credit * 100)) as d')->value('d'))->toBe(0)
        ->and(DB::table('branch_products')->where('company_id', $id)->where('qty_on_hand', '<', 0)->count())->toBeGreaterThan(0)
        ->and(DB::table('customers')->where('company_id', $id)->where('balance', '>', 0)->count())->toBeGreaterThan(0)
        ->and(DB::table('news_titles as n')->join('products as p', 'p.id', '=', 'n.linked_product_id')->join('vat_rates as v', 'v.id', '=', 'p.vat_rate_id')
            ->where('n.company_id', $id)->where('v.percentage', '>', 0)->count())->toBe(0);

    $this->artisan('reports:check', ['--company' => [$id]])->assertSuccessful();

    // A second run stores nothing twice.
    $this->artisan('demo:seed', ['--company' => $id, '--days' => 2])->assertSuccessful();
    expect(demoSeedCounts($id))->toBe($counts);

    // --fresh removes only what the demo made: the till's own supplier stays.
    $this->artisan('demo:seed', ['--company' => $id, '--days' => 1, '--fresh' => true])
        ->expectsOutputToContain('earlier demo rows removed')
        ->assertSuccessful();
    expect(DB::table('suppliers')->where('id', $this->ownSupplier)->exists())->toBeTrue()
        ->and(DB::table('sales')->where('company_id', $id)->where('trading_day', '<', '2026-10-01')->count())->toBe(0)
        ->and(demoSeedCounts($this->other->id))->toBe($otherBefore);
});

it('refuses to run in production and writes nothing', function () {
    $this->app['env'] = 'production';

    $this->artisan('demo:seed', ['--company' => 'Corner Demo Stores'])->expectsOutputToContain('never runs in production')->assertFailed();

    expect(fn () => app(SeedDemoBusiness::class)->handle($this->shop, 1))->toThrow(RuntimeException::class)
        ->and(DB::table('products')->count())->toBe(0)
        ->and(DB::table('sales')->count())->toBe(0);
});

it('says what is wrong with the options', function () {
    $this->artisan('demo:seed', ['--company' => 'Nobody Stores'])->expectsOutputToContain('No business matches')->assertFailed();
    $this->artisan('demo:seed', ['--days' => 0])->expectsOutputToContain('--days must be')->assertExitCode(2);
});
