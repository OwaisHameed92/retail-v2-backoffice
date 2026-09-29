<?php

use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Enums\SaleStatus;
use App\Domain\TillData\Exceptions\ReadOnlyTillRow;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\TillData\TillFixtures;

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->push = TillFixtures::sample('push-request.json');
    $this->change = fn (string $entity) => collect($this->push)->firstWhere('entity', $entity);
    $this->apply = fn (array $changes, $branch = null) => TillFixtures::apply($this->company, $branch ?? $this->leeds, $changes);
});

/** Raw decimal from either driver (SQLite: float, MySQL: fixed-scale string) as a fixed-scale string. */
function tillMoney(mixed $value): string
{
    return Money::normalise($value, 2);
}

function tillQty(mixed $value): string
{
    return Money::normalise($value, 4);
}

/**
 * A copy of an envelope with a new seq/version and payload changes.
 */
function tillChange(array $envelope, int $seq, int $version, array $payload = [], array $envelopeOverrides = []): array
{
    $envelope['seq'] = $seq;
    $envelope['version'] = $version;
    $envelope['payload'] = [...$envelope['payload'], 'rowVersion' => $version, ...$payload];
    $envelope['key'] = "{$envelope['entity']}:{$envelope['entityId']}:{$version}";

    return [...$envelope, ...$envelopeOverrides];
}

it('gives the same reply and changes nothing when the same batch is sent twice', function () {
    $first = ($this->apply)($this->push);
    $snapshot = DB::table('sales')->get()->toArray();
    $second = ($this->apply)($this->push);

    expect($second->toPushReply())->toBe($first->toPushReply())
        ->and($second->count(ChangeOutcome::Duplicate))->toBe(9)
        ->and(DB::table('sales')->get()->toArray())->toEqual($snapshot)
        ->and(DB::table('sale_lines')->count())->toBe(2)
        ->and(DB::table('sync_applied_changes')->count())->toBe(9);
});

it('dedupes on (entity, entityId, version) even without a seq, as pull replays do', function () {
    $sale = ($this->change)('Sale');
    ($this->apply)([tillChange($sale, 0, 1)]);
    $again = ($this->apply)([tillChange($sale, 0, 1)]);

    expect($again->count(ChangeOutcome::Stale))->toBe(1)
        ->and($again->accepted)->toBe(1)
        ->and(DB::table('sync_applied_changes')->count())->toBe(0);
});

it('keeps the highest version: an older version is accepted but changes nothing', function () {
    $order = ($this->change)('CustomerOrder');
    ($this->apply)([tillChange($order, 10, 5, ['status' => 'collected'])]);
    $stale = ($this->apply)([tillChange($order, 11, 4, ['status' => 'cancelled'])]);

    expect(TillFixtures::ack($stale))->toBe(['acknowledgedSeq' => 11, 'accepted' => 1])
        ->and($stale->count(ChangeOutcome::Stale))->toBe(1)
        ->and(DB::table('customer_orders')->value('status'))->toBe('collected')
        ->and(DB::table('customer_orders')->value('row_version'))->toBe(5);
});

it('applies the newest of several versions of one row in one batch', function () {
    $order = ($this->change)('CustomerOrder');
    $result = ($this->apply)([
        tillChange($order, 20, 3, ['status' => 'ready']),
        tillChange($order, 21, 4, ['status' => 'collected']),
    ]);

    expect($result->accepted)->toBe(2)
        ->and(DB::table('customer_orders')->value('status'))->toBe('collected');
});

it('soft-deletes on D, keeping the payload', function () {
    ($this->apply)($this->push);
    $movement = ($this->change)('StockMovement');
    ($this->apply)([tillChange($movement, 18300, 2, ['deletedAt' => '2026-09-23T10:00:00Z', 'note' => 'Rung in error'], ['op' => 'D'])]);

    $row = DB::table('stock_movements')->where('id', $movement['entityId'])->first();
    expect($row->deleted_at)->toBe('2026-09-23 10:00:00')
        ->and($row->note)->toBe('Rung in error')
        ->and($row->row_version)->toBe(2);

    app(CurrentCompany::class)->runAs($this->company, function () use ($movement) {
        expect(StockMovement::find($movement['entityId']))->toBeNull()
            ->and(StockMovement::withTrashed()->find($movement['entityId']))->not->toBeNull();
    });
});

it('soft-deletes on D without a payload, using the change time', function () {
    ($this->apply)($this->push);
    $movement = ($this->change)('StockMovement');
    $result = ($this->apply)([[...tillChange($movement, 18300, 2), 'op' => 'D', 'payload' => null, 'at' => '2026-09-23T11:00:00Z']]);

    expect($result->accepted)->toBe(1)
        ->and(DB::table('stock_movements')->where('id', $movement['entityId'])->value('deleted_at'))->toBe('2026-09-23 11:00:00');
});

it('stores a child that arrives before its sale and gives it the sale\'s till when the sale lands', function () {
    $line = ($this->change)('SaleLine');
    $sale = ($this->change)('Sale');

    ($this->apply)([tillChange($line, 100, 1)]);
    expect(DB::table('sale_lines')->where('id', $line['entityId'])->first())
        ->branch_id->toBe(TillFixtures::LEEDS)
        ->register_id->toBeNull();

    ($this->apply)([tillChange($sale, 101, 1)]);
    expect(DB::table('sale_lines')->where('id', $line['entityId'])->value('register_id'))->toBe(TillFixtures::TILL_1);
});

it('resolves a child from its sale later in the same batch', function () {
    $result = ($this->apply)([tillChange(($this->change)('SaleLine'), 100, 1), tillChange(($this->change)('Sale'), 101, 1)]);

    expect($result->accepted)->toBe(2)
        ->and(DB::table('sale_lines')->value('register_id'))->toBe(TillFixtures::TILL_1);
});

it('rejects changes of another company', function () {
    $other = Company::factory()->create();
    $sale = ($this->change)('Sale');

    $result = ($this->apply)([
        tillChange($sale, 1, 1, [], ['companyId' => $other->id]),
        tillChange(($this->change)('SaleLine'), 2, 1, ['companyId' => $other->id]),
    ]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_company', 'sync.wrong_company'])
        ->and($result->accepted)->toBe(0)
        ->and($result->acknowledgedSeq)->toBe(0);
});

it('rejects branch rows of another branch and tills of another branch', function () {
    $sale = ($this->change)('Sale');

    $result = ($this->apply)([
        tillChange($sale, 1, 1, ['branchId' => TillFixtures::BRADFORD], ['branchId' => '']),
        tillChange(($this->change)('StockMovement'), 2, 1, ['registerId' => TillFixtures::BRADFORD_TILL], ['registerId' => '']),
        tillChange(($this->change)('CustomerOrder'), 3, 2, [], ['registerId' => TillFixtures::BRADFORD_TILL]),
    ]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_branch', 'sync.unknown_register', 'sync.unknown_register'])
        ->and(DB::table('sales')->count())->toBe(0);
});

it('keeps unknown future members in extra, redacting secret-looking ones, and drops derived members', function () {
    $sale = ($this->change)('Sale');
    ($this->apply)([tillChange($sale, 1, 1, ['loyaltyTier' => 'gold', 'basket' => ['items' => 2, 'weight' => 1.5], 'terminalApiKey' => 'abc123', 'isCompleted' => true])]);

    $extra = json_decode(DB::table('sales')->value('extra'), true);
    // toEqual: MySQL's json column re-orders keys.
    expect($extra)->toEqual(['loyaltyTier' => 'gold', 'basket' => ['items' => 2, 'weight' => 1.5], 'terminalApiKey' => '[redacted]']);
});

it('rejects a malformed row with its key while the others apply, and acknowledges up to it', function () {
    $movement = ($this->change)('StockMovement');
    $bad = tillChange(($this->change)('SaleLine'), 18232, 1, ['qty' => 'two', 'unitPrice' => null]);
    unset($bad['payload']['barcode']);
    $batch = $this->push;
    $batch[1] = $bad;

    $result = ($this->apply)($batch);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 18231, 'accepted' => 8])
        ->and($result->rejected)->toHaveCount(1)
        ->and($result->rejected[0]->key)->toBe('SaleLine:01K5VB0000000SN1R001000482:1')
        ->and($result->rejected[0]->code)->toBe('payload.invalid')
        ->and($result->rejected[0]->message)->toContain('qty must be a number', 'unitPrice must not be null', 'barcode is missing')
        ->and(DB::table('stock_movements')->where('id', $movement['entityId'])->exists())->toBeTrue()
        ->and(DB::table('sale_lines')->count())->toBe(1);

    // The till resends from the rejected row; once fixed, everything is acknowledged.
    $retry = ($this->apply)(array_slice(TillFixtures::sample('push-request.json'), 1));
    expect(TillFixtures::ack($retry))->toBe(['acknowledgedSeq' => 18239, 'accepted' => 8])
        ->and($retry->count(ChangeOutcome::Duplicate))->toBe(7);
});

it('reads numbers sent as strings, as the till itself does', function () {
    $line = ($this->change)('SaleLine');
    $result = ($this->apply)([[...tillChange($line, 1, 1, ['qty' => '2.5', 'unitPrice' => '1.85', 'position' => '3']), 'seq' => '1', 'version' => '1']]);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 1, 'accepted' => 1])
        ->and(DB::table('sale_lines')->first())
        ->position->toBe(3)
        ->and(tillQty(DB::table('sale_lines')->value('qty')))->toBe('2.5000');
});

it('rejects malformed envelopes without failing the batch', function () {
    $sale = ($this->change)('Sale');
    $result = ($this->apply)([
        'not an object',
        [...$sale, 'seq' => 5, 'op' => 'X', 'entityId' => 'lowercase-not-a-ulid'],
        [...$sale, 'seq' => 6, 'entity' => 'WebOrder'],
        [...$sale, 'seq' => 7, 'at' => 'yesterday'],
        tillChange($sale, 8, 1),
    ]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['change.invalid', 'change.invalid', 'entity.unknown', 'change.invalid'])
        ->and($result->accepted)->toBe(1)
        ->and($result->acknowledgedSeq)->toBe(0);
});

it('acknowledges across gaps in seq numbering and stops at the first rejection', function () {
    $sale = ($this->change)('Sale');
    $order = ($this->change)('CustomerOrder');

    $gap = ($this->apply)([tillChange($sale, 100, 1), tillChange($order, 105, 2)]);
    expect($gap->acknowledgedSeq)->toBe(105);

    $firstBad = ($this->apply)([tillChange($order, 200, 3, ['status' => 'nope', 'goodsTotal' => 'x']), tillChange($order, 201, 4)]);
    expect($firstBad->acknowledgedSeq)->toBe(199)
        ->and($firstBad->accepted)->toBe(1);
});

it('stores rows in seq order whatever order they arrive in', function () {
    $order = ($this->change)('CustomerOrder');
    $result = ($this->apply)([tillChange($order, 31, 4, ['status' => 'collected']), tillChange($order, 30, 3, ['status' => 'ready'])]);

    expect($result->acknowledgedSeq)->toBe(31)
        ->and(DB::table('customer_orders')->value('status'))->toBe('collected');
});

it('rejects a seq that appears twice in one batch', function () {
    $result = ($this->apply)([tillChange(($this->change)('Sale'), 7, 1), tillChange(($this->change)('CustomerOrder'), 7, 2)]);

    expect($result->rejected[0]->code)->toBe('sync.duplicate_seq')->and($result->acknowledgedSeq)->toBe(7);
});

it('never rewrites a completed sale\'s history, but applies a void and records the attempt', function () {
    ($this->apply)($this->push);
    $sale = ($this->change)('Sale');

    $result = ($this->apply)([tillChange($sale, 18300, 2, [
        'total' => 99.99,
        'status' => 'voided',
        'voidedBy' => '01K5T0Q8C4000000000000A001',
        'deletedAt' => null,
    ])]);

    $row = DB::table('sales')->first();
    expect($result->count(ChangeOutcome::Applied))->toBe(1)
        ->and($row->status)->toBe('voided')
        ->and($row->voided_by)->toBe('01K5T0Q8C4000000000000A001')
        ->and(tillMoney($row->total))->toBe('5.15')
        ->and($row->row_version)->toBe(2)
        ->and(DB::table('sync_conflicts')->where('kind', 'immutableChange')->value('detail'))->toContain('total');
});

it('freezes sale lines once their sale is completed, but not while it is open', function () {
    $sale = ($this->change)('Sale');
    $line = ($this->change)('SaleLine');

    ($this->apply)([
        tillChange($sale, 1, 1, ['status' => 'open', 'completedAt' => null]),
        tillChange($line, 2, 1, ['qty' => 1]),
        tillChange($line, 3, 2, ['qty' => 3]),
        tillChange($sale, 4, 2),
    ]);
    ($this->apply)([tillChange($line, 5, 3, ['qty' => 9])]);
    expect(tillQty(DB::table('sale_lines')->value('qty')))->toBe('3.0000')
        ->and(DB::table('sale_lines')->value('row_version'))->toBe(3);

    // An update with seq below the completion but in the same batch is still the open sale's.
    ($this->apply)([tillChange($line, 6, 4, ['qty' => 4]), tillChange($sale, 7, 3, ['status' => 'open', 'completedAt' => null])]);
    expect(tillQty(DB::table('sale_lines')->value('qty')))->toBe('3.0000');

    DB::table('sales')->update(['status' => 'open']);
    ($this->apply)([tillChange($line, 8, 5, ['qty' => 5]), tillChange($sale, 9, 4)]);
    expect(tillQty(DB::table('sale_lines')->value('qty')))->toBe('5.0000');
});

it('keeps the portal\'s newer edit of a hub-owned row and records a conflict', function () {
    $product = TillFixtures::sample('entities/Product.json');
    ($this->apply)([TillFixtures::envelope('Product', $product, 0, ['version' => 5])]);

    app(CurrentCompany::class)->runAs($this->company, function () use ($product) {
        $model = Product::findOrFail($product['id']);
        $model->sell_price = '1.50';
        $model->save();
    });

    $tillEdit = TillFixtures::envelope('Product', [...$product, 'sellPrice' => 1.39], 40, ['version' => 6, 'at' => '2026-09-23T09:00:00Z']);
    $result = ($this->apply)([$tillEdit]);

    expect($result->count(ChangeOutcome::Conflict))->toBe(1)
        ->and($result->acknowledgedSeq)->toBe(40)
        ->and(tillMoney(DB::table('products')->value('sell_price')))->toBe('1.50')
        ->and(DB::table('sync_conflicts')->first())
        ->kind->toBe('hubEditNewer')
        ->entity->toBe('Product')
        ->incoming_version->toBe(6);

    $later = TillFixtures::envelope('Product', [...$product, 'sellPrice' => 1.35], 41, ['version' => 7, 'at' => now()->addMinute()->toIso8601ZuluString()]);
    expect(($this->apply)([$later])->count(ChangeOutcome::Applied))->toBe(1)
        ->and(tillMoney(DB::table('products')->value('sell_price')))->toBe('1.35');
});

it('never overwrites a row id another company holds', function () {
    $other = Company::factory()->create();
    $product = TillFixtures::sample('entities/Product.json');
    DB::table('products')->insert(['id' => $product['id'], 'company_id' => $other->id, 'name' => 'Theirs', 'row_version' => 1]);

    $result = ($this->apply)([TillFixtures::envelope('Product', $product, 1, ['version' => 2])]);

    expect($result->rejected[0]->code)->toBe('entity.id_taken')
        ->and(DB::table('products')->value('name'))->toBe('Theirs')
        ->and(DB::table('products')->value('company_id'))->toBe($other->id);
});

it('updates only till-owned fields of the portal\'s company, branch and register rows', function () {
    $branch = ['code' => 'XXX', 'name' => 'Leeds Kirkgate', 'address' => '14 Kirkgate, Leeds', 'phone' => '0113 000', 'vatNumber' => 'GB1', 'nation' => 'england', 'licensedHoursJson' => '{"mon":"06:00-23:00"}', 'isDrsReturnPoint' => true, 'areaM2' => 82.5, 'nextPoNo' => 12, 'isActive' => false, 'id' => TillFixtures::LEEDS, 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-01T08:00:00Z', 'updatedAt' => '2026-09-23T08:00:00Z', 'rowVersion' => 3, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => []];
    $register = ['code' => '09', 'name' => 'Front counter', 'nextSaleNo' => 483, 'nextRefundNo' => 7, 'nextOrderNo' => 18, 'isMainTill' => false, 'isActive' => false, 'branchId' => TillFixtures::LEEDS, 'id' => TillFixtures::TILL_1, 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-01T08:00:00Z', 'updatedAt' => '2026-09-23T08:00:00Z', 'rowVersion' => 9, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => []];

    $result = ($this->apply)([
        TillFixtures::envelope('Branch', $branch, 1),
        TillFixtures::envelope('Register', $register, 2),
        TillFixtures::envelope('Branch', [...$branch, 'id' => TillFixtures::BRADFORD], 3),
        TillFixtures::envelope('Register', [...$register, 'id' => TillFixtures::BRADFORD_TILL], 4),
    ]);

    expect($result->accepted)->toBe(2)
        ->and(collect($result->rejected)->pluck('code')->all())->toBe(['sync.wrong_branch', 'sync.unknown_register'])
        ->and(DB::table('branches')->where('id', TillFixtures::LEEDS)->first())
        ->code->toBe('LDS')
        ->name->toBe('Leeds Kirkgate')
        ->next_po_no->toBe(12)
        ->is_active->toBe(1)
        ->till_row_version->toBe(3)
        ->and(DB::table('registers')->where('id', TillFixtures::TILL_1)->first())
        ->code->toBe('01')
        ->name->toBe('Front counter')
        ->next_sale_no->toBe(483)
        ->next_order_no->toBe(18)
        ->is_main_till->toBe(1)
        ->is_active->toBe(1);

    $delete = ($this->apply)([TillFixtures::envelope('Branch', [...$branch, 'rowVersion' => 4, 'deletedAt' => '2026-09-24T00:00:00Z'], 5, ['op' => 'D'])]);
    expect($delete->accepted)->toBe(1)
        ->and(DB::table('branches')->where('id', TillFixtures::LEEDS)->value('deleted_at'))->toBeNull()
        ->and(DB::table('sync_conflicts')->value('kind'))->toBe('tenancyDelete');
});

it('never stores the till\'s licence key, only its hash and last 4 characters', function () {
    $licence = ['licenceKey' => 'SSP-7K3M-Q9TZ-4HPA-B6WN', 'trialStartedAt' => '2026-09-01T08:00:00Z', 'trialEndsAt' => '2026-09-08T08:00:00Z', 'activatedAt' => null, 'expiresAt' => null, 'graceDays' => 3, 'isDealer' => false, 'branchLimit' => 1, 'registerLimit' => 1, 'deviceId' => 'PC-1', 'id' => '01K5T0Q8C4000000000000N001', 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-01T08:00:00Z', 'updatedAt' => '2026-09-01T08:00:00Z', 'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => []];

    $result = ($this->apply)([TillFixtures::envelope('Licence', $licence, 1)]);
    $row = (array) DB::table('till_licences')->first();

    expect($result->accepted)->toBe(1)
        ->and($row['licence_key_last4'])->toBe('B6WN')
        ->and($row['licence_key_hash'])->toHaveLength(64)
        ->and($row['branch_id'])->toBe(TillFixtures::LEEDS)
        ->and(json_encode($row))->not->toContain('7K3M');
});

it('stores an enum value the contract does not list yet, reads it as null and logs it', function () {
    Log::spy();
    $sale = ($this->change)('Sale');
    $result = ($this->apply)([tillChange($sale, 1, 1, ['type' => 'layaway'])]);

    expect($result->accepted)->toBe(1)
        ->and(DB::table('sales')->value('type'))->toBe('layaway');

    app(CurrentCompany::class)->runAs($this->company, fn () => expect(Sale::firstOrFail()->type)->toBeNull()
        ->and(Sale::firstOrFail()->status)->toBe(SaleStatus::Completed));
    Log::shouldHaveReceived('warning')->once();
});

it('rejects only the change the database refuses and applies the rest', function () {
    Log::spy();
    DB::statement('DROP TABLE stock_movements');

    $result = ($this->apply)($this->push);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 18236, 'accepted' => 7])
        ->and(collect($result->rejected)->pluck('code')->all())->toBe(['store.failed', 'store.failed'])
        ->and(collect($result->rejected)->pluck('seq')->all())->toBe([18237, 18238])
        ->and(DB::table('sale_vats')->count())->toBe(2)
        ->and(DB::table('customer_orders')->count())->toBe(1);
    Log::shouldHaveReceived('error')->twice();
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'Dropping a table commits the test transaction on MySQL.');

it('makes till-owned rows read-only through Eloquent', function () {
    ($this->apply)($this->push);

    app(CurrentCompany::class)->runAs($this->company, function () {
        $line = SaleLine::firstOrFail();
        $line->qty = '9';

        expect(fn () => $line->save())->toThrow(ReadOnlyTillRow::class)
            ->and(fn () => $line->delete())->toThrow(ReadOnlyTillRow::class);
    });
});
