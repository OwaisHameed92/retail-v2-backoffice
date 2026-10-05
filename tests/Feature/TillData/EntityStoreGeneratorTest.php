<?php

use App\Domain\TillData\Actions\GenerateTillEntities;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Generator\MigrationPlanner;
use App\Domain\TillData\Generator\Php;
use App\Domain\TillData\Generator\SchemaCatalog;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\TillData\TillFixtures;

function tillSchemaEntities(): array
{
    return array_map(
        fn (string $file) => basename($file, '.schema.json'),
        glob(base_path(TillFixtures::CONTRACT.'/schemas/entities/*.schema.json')),
    );
}

it('is idempotent: regenerating changes no file', function () {
    $result = app(GenerateTillEntities::class)->handle(write: false);

    expect($result['changed'])->toBe([])
        ->and($result['deleted'])->toBe([])
        ->and($result['unchanged'])->toBeGreaterThan(200);
});

it('has a registry entry, a table with every mapped column, and a final generated model for every schema entity', function () {
    $schemas = tillSchemaEntities();
    $entities = array_values(array_diff($schemas, EntityRegistry::LOCAL));

    // Till 0.1.51 pack: 146 schemas (+ AccountPayDate); the `local` ones (EventSubscription since till 0.1.38) are
    // never synced, never stored, so no registry entry (event_subscriptions stays, additive migrations only).
    expect($schemas)->toHaveCount(146)
        ->and(EntityRegistry::LOCAL)->toBe(['DomainEventRecord', 'EventSubscription', 'ProcessedCommand', 'SyncState'])
        ->and($entities)->toHaveCount(144)
        ->and(EntityRegistry::names())->toEqualCanonicalizing($entities);

    foreach ($entities as $entity) {
        $def = EntityRegistry::get($entity);

        expect(Schema::hasTable($def->table))->toBeTrue("{$entity}: table {$def->table} is missing");

        if ($def->tenancy) {
            continue;
        }

        $columns = Schema::getColumnListing($def->table);
        expect(array_diff($def->columns, $columns))->toBe([], "{$entity}: missing columns")
            ->and(class_exists($def->model))->toBeTrue()
            ->and((new ReflectionClass($def->model))->isFinal())->toBeTrue()
            ->and((string) file_get_contents((new ReflectionClass($def->model))->getFileName()))->toContain(Php::GENERATED_TAG)
            ->and((new $def->model)->getTable())->toBe($def->table);
    }
});

it('uses exactly the ownership in samples/ownership.json', function () {
    $ownership = TillFixtures::sample('ownership.json')['entities'];

    foreach (EntityRegistry::names() as $entity) {
        expect(EntityRegistry::get($entity)->ownership)->toBe($ownership[$entity], $entity);
    }

    // Every table the till lists is stored, except the `local` ones (v1.4: never pushed, never pulled).
    expect(array_values(array_diff(array_keys($ownership), EntityRegistry::names())))->toBe(EntityRegistry::LOCAL)
        ->and(array_keys(array_filter($ownership, fn (string $owner) => $owner === 'local')))->toBe(EntityRegistry::LOCAL)
        ->and(EntityRegistry::get('Setting')->keyedBy)->toBe(['scope', 'scopeId', 'key'])
        ->and(EntityRegistry::get('RolePermission')->keyedBy)->toBe(['roleId', 'permissionKey'])
        ->and(EntityRegistry::get('BranchPrice')->ownership)->toBe('hub')
        ->and(EntityRegistry::get('BranchPrice')->scope)->toBe('branch');
});

it('generates PHP backed enums with exactly the values of samples/enums.json', function () {
    foreach (TillFixtures::sample('enums.json') as $name => $values) {
        $class = "App\\Domain\\TillData\\Enums\\{$name}";

        expect(enum_exists($class))->toBeTrue($name)
            ->and(array_map(fn (BackedEnum $case) => $case->value, $class::cases()))->toBe($values);
    }
});

it('maps company, branch and register rows onto the tenancy tables without generating models for them', function () {
    expect(EntityRegistry::get('Company')->table)->toBe('companies')
        ->and(EntityRegistry::get('Branch')->table)->toBe('branches')
        ->and(EntityRegistry::get('Register')->table)->toBe('registers')
        ->and(file_exists(app_path('Domain/TillData/Models/Company.php')))->toBeFalse()
        ->and(EntityRegistry::get('Licence')->table)->toBe('till_licences')
        ->and(Schema::getColumnListing('till_licences'))->toContain('licence_key_hash', 'licence_key_last4')
        ->not->toContain('licence_key');
});

it('classifies scopes and parents as the contract describes', function () {
    expect(EntityRegistry::get('Sale')->scope)->toBe('register')
        ->and(EntityRegistry::get('CustomerOrder')->scope)->toBe('branch')
        ->and(EntityRegistry::get('Product')->scope)->toBe('company')
        ->and(EntityRegistry::get('FinancialYear')->scope)->toBe('sender')
        ->and(EntityRegistry::get('SaleLine')->scope)->toBe('child')
        ->and(EntityRegistry::get('SaleLine')->parent)->toMatchArray(['entity' => 'Sale', 'column' => 'sale_id'])
        ->and(EntityRegistry::get('SaleLine')->scopeColumns)->toBe(['branch_id', 'register_id'])
        ->and(EntityRegistry::get('CustomerOrderPayment')->scopeColumns)->toBe(['branch_id'])
        ->and(EntityRegistry::get('JournalLine')->scopeColumns)->toBe(['branch_id', 'register_id'])
        ->and(EntityRegistry::get('Sale')->children)->toBe(['SaleLine', 'SalePayment', 'SaleVat']);
});

it('stores money as decimal(12,2), costs and quantities as decimal(14,4)', function () {
    $fields = EntityRegistry::get('SaleLine')->fields;

    expect($fields['unitPrice']->type)->toBe('money')
        ->and($fields['lineTotal']->type)->toBe('money')
        ->and($fields['costAtSale']->type)->toBe('cost')
        ->and($fields['qty']->type)->toBe('quantity')
        ->and($fields['vatPercentage']->type)->toBe('percent')
        ->and(EntityRegistry::get('Sale')->fields['receiptJson']->type)->toBe('longText')
        ->and(EntityRegistry::get('Sale')->derived)->toContain('isCompleted', 'isDeleted', 'domainEvents')
        ->and(EntityRegistry::get('CustomerOrder')->derived)->toContain('balanceDue')
        ->and(EntityRegistry::get('Product')->derived)->toContain('priceIncVat', 'barcodes', 'primaryBarcode');
});

it('writes additive migrations: applied releases are never rewritten, the current one adds only what they lack', function () {
    $lock = json_decode((string) file_get_contents(base_path(MigrationPlanner::LOCK)), true);
    $produced = array_keys(app(GenerateTillEntities::class)->render(new SchemaCatalog(base_path())));
    $previous = $lock['releases'][1];
    $v141 = $lock['releases'][2];
    $v141b = $lock['releases'][3];
    $till0115 = $lock['releases'][4];
    $current = $lock['releases'][5];

    expect(array_column($lock['releases'], 'release'))->toBe(['v1.1', 'v1.3.1', 'v1.4.1', 'v1.4.1-b', '0.1.15', '0.1.51'])
        ->and($previous['migrations'])->toBe(['2026_10_02_100000_create_till_v1_3_1_tables.php', '2026_10_02_100001_add_till_v1_3_1_columns.php'])
        ->and($previous['tables']['products']['columns'])->toHaveKeys(['variant3_name', 'hub_hash', 'origin_branch_id', 'portal_received_at'])
        ->and($v141['migrations'])->toBe(['2026_10_06_100000_create_till_v1_4_1_tables.php', '2026_10_06_100001_add_till_v1_4_1_columns.php'])
        ->and(array_keys($v141['tables']))->toEqualCanonicalizing([
            'branch_prices', 'purchase_returns', 'purchase_return_lines', 'till_settings', 'till_role_permissions',
            'purchase_orders', 'goods_receipt_lines', 'stock_transfers', 'supplier_invoices', 'till_sync_conflicts',
        ])
        ->and($v141['tables']['purchase_orders']['columns'])->toHaveKeys(['origin', 'branch_code', 'reference'])
        ->not->toHaveKeys(['is_from_head_office', 'has_receiving_started', 'supplier_id'])
        ->and($v141['tables']['goods_receipt_lines']['columns'])->toBe(['damaged_qty' => "decimal('damaged_qty', 14, 4)->nullable()"])
        ->and($v141['tables']['till_sync_conflicts']['columns'])->toBe(['hub_change' => "longText('hub_change')->nullable()"])
        // ANSWERS-2026-09-29-b: PromotionRule.isGroupOffer arrives in its own additive migration.
        ->and($v141b['migrations'])->toBe(['2026_10_10_100001_add_till_v1_4_1_b_columns.php'])
        ->and($v141b['tables'])->toBe(['promotion_rules' => ['columns' => ['is_group_offer' => "boolean('is_group_offer')->nullable()"], 'indexes' => []]])
        // Till 0.1.15 pack: ClockEvent.registerId and StoreCreditVoucher.note, in their own additive migration.
        ->and($till0115['migrations'])->toBe(['2026_10_25_100001_add_till_0_1_15_columns.php'])
        ->and($till0115['tables'])->toBe([
            'clock_events' => ['columns' => ['register_id' => "string('register_id', 64)->nullable()"], 'indexes' => []],
            'store_credit_vouchers' => ['columns' => ['note' => "text('note')->nullable()"], 'indexes' => []],
        ])
        // Till 0.1.51 pack: the AccountPayDate table, then Customer / CustomerTransaction / CustomerOrder / RotaShift columns.
        ->and($current['migrations'])->toBe(['2026_11_24_100000_create_till_0_1_51_tables.php', '2026_11_24_100001_add_till_0_1_51_columns.php'])
        ->and(array_keys($current['tables']))->toEqualCanonicalizing(['account_pay_dates', 'customers', 'customer_transactions', 'customer_orders', 'rota_shifts'])
        ->and(array_keys($current['tables']['customer_transactions']['columns']))->toBe(['tender', 'register_id', 'shift_id'])
        ->and(array_keys($current['tables']['customers']['columns']))->toBe(['pending_points', 'earns_points']);

    foreach ([...$lock['releases'][0]['migrations'], ...$previous['migrations'], ...$v141['migrations'], ...$v141b['migrations'], ...$till0115['migrations']] as $applied) {
        expect(file_exists(database_path("migrations/{$applied}")))->toBeTrue()
            ->and($produced)->not->toContain("database/migrations/{$applied}");
    }
});

it('models the v1.3 entities: transfers, their lines and receipts, and the hub-owned medicine class', function () {
    expect(EntityRegistry::get('StockTransferLine')->parent)->toMatchArray(['entity' => 'StockTransfer', 'column' => 'transfer_id'])
        ->and(EntityRegistry::get('StockTransferReceiptLine')->parent)->toMatchArray(['entity' => 'StockTransferReceipt', 'column' => 'receipt_id'])
        ->and(EntityRegistry::get('StockTransferLine')->scope)->toBe('branch')
        ->and(EntityRegistry::get('StockTransferLine')->fields['qtyDispatched']->type)->toBe('quantity')
        ->and(EntityRegistry::get('StockTransfer')->fields['dispatchedCost']->type)->toBe('cost')
        ->and(EntityRegistry::get('MedicineClassification')->ownership)->toBe('hub')
        ->and(EntityRegistry::get('SaleLine')->fields['baseQty']->type)->toBe('quantity')
        ->and(EntityRegistry::get('User')->dropped)->toBe(['remoteApprovalSecret', 'remoteApprovalSecretSetAt'])
        ->and(EntityRegistry::get('User')->fields)->not->toHaveKeys(['remoteApprovalSecret', 'remoteApprovalSecretSetAt'])
        ->and(Schema::getColumnListing('products'))->toContain('hub_hash', 'origin_branch_id', 'portal_received_at');
});
