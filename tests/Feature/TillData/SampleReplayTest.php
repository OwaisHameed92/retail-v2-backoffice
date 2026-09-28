<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Enums\CustomerOrderStatus;
use App\Domain\TillData\Enums\DiscountSource;
use App\Domain\TillData\Enums\SalePaymentStatus;
use App\Domain\TillData\Enums\SaleStatus;
use App\Domain\TillData\Enums\SaleType;
use App\Domain\TillData\Enums\StockMovementType;
use App\Domain\TillData\Enums\UnitType;
use App\Domain\TillData\Enums\VatTreatment;
use App\Domain\TillData\Models\CustomerOrder;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Models\SalePayment;
use App\Domain\TillData\Models\SaleVat;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Models\VatRate;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\Values;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

it('replays push-request.json and replies exactly like push-reply.json', function () {
    $result = TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));

    expect($result->toPushReply())->toBe(TillFixtures::sample('push-reply.json'))
        ->and($result->rejected)->toBe([])
        ->and($result->count(ChangeOutcome::Applied))->toBe(9);

    ($this->asCompany)(function () {
        $sale = Sale::findOrFail('01K5VB000000000SR001000482');
        expect($sale->total)->toBe('5.15')
            ->and($sale->subtotal)->toBe('5.15')
            ->and($sale->vat_total)->toBe('0.62')
            ->and($sale->discount_total)->toBe('0.00')
            ->and($sale->status)->toBe(SaleStatus::Completed)
            ->and($sale->type)->toBe(SaleType::Sale)
            ->and($sale->receipt_number)->toBe('LDS-01-000482')
            ->and($sale->number)->toBe(482)
            ->and($sale->no_receipt)->toBeFalse()
            ->and($sale->completed_at?->toIso8601ZuluString())->toBe('2026-09-23T09:41:12Z')
            ->and($sale->branch_id)->toBe(TillFixtures::LEEDS)
            ->and($sale->register_id)->toBe(TillFixtures::TILL_1)
            ->and($sale->row_version)->toBe(1)
            ->and($sale->sync_seq)->toBe(18231)
            ->and($sale->receipt_json)->toBe('')
            ->and($sale->extra)->toBeNull()
            ->and($sale->saleLines()->count())->toBe(2);

        $cola = SaleLine::findOrFail('01K5VB0000000SN2R001000482');
        expect($cola->qty)->toBe('2.0000')
            ->and($cola->unit_price)->toBe('1.85')
            ->and($cola->line_total)->toBe('3.70')
            ->and($cola->vat_amount)->toBe('0.62')
            ->and($cola->vat_percentage)->toBe('20.0000')
            ->and($cola->cost_at_sale)->toBe('0.7800')
            ->and($cola->discount_source)->toBe(DiscountSource::Manual)
            ->and($cola->age_verified_dob)->toBeNull()
            // Child rows carry no branch or till: from the sale (contract §16).
            ->and($cola->branch_id)->toBe(TillFixtures::LEEDS)
            ->and($cola->register_id)->toBe(TillFixtures::TILL_1)
            ->and($cola->sale->id)->toBe('01K5VB000000000SR001000482');

        $payment = SalePayment::findOrFail('01K5VB00000000SPR001000482');
        expect($payment->amount)->toBe('5.15')
            ->and($payment->status)->toBe(SalePaymentStatus::Approved)
            ->and($payment->last4)->toBe('4921')
            ->and($payment->exchange_rate)->toBe('0.000000')
            ->and($payment->register_id)->toBe(TillFixtures::TILL_1);

        $vats = SaleVat::query()->orderBy('code')->get();
        expect($vats->pluck('gross')->all())->toBe(['3.70', '1.45'])
            ->and($vats->pluck('net')->all())->toBe(['3.08', '1.45'])
            ->and($vats->pluck('register_id')->unique()->all())->toBe([TillFixtures::TILL_1]);

        $movement = StockMovement::findOrFail('01K5VB00000000M2R001000482');
        expect($movement->qty_delta)->toBe('-2.0000')
            ->and($movement->qty_before)->toBe('48.0000')
            ->and($movement->unit_cost)->toBe('0.7800')
            ->and($movement->type)->toBe(StockMovementType::Sale)
            ->and($movement->at?->toIso8601ZuluString())->toBe('2026-09-23T09:41:12Z')
            ->and($movement->ref_line_id)->toBe('01K5VB0000000SN2R001000482');

        $order = CustomerOrder::findOrFail('01K5VC7N2W000000000000W001');
        $sample = collect(TillFixtures::sample('push-request.json'))->firstWhere('entity', 'CustomerOrder')['payload'];
        expect($order->status)->toBe(CustomerOrderStatus::Ready)
            ->and($order->goods_total)->toBe('10.30')
            ->and($order->paid_total)->toBe('0.00')
            ->and($order->lines_json)->toBe($sample['linesJson'])
            ->and($order->due_date?->toIso8601ZuluString())->toBe('2026-09-26T00:00:00Z')
            ->and($order->created_at?->toIso8601ZuluString())->toBe('2026-09-23T08:52:12Z')
            ->and($order->row_version)->toBe(2)
            ->and($order->branch_id)->toBe(TillFixtures::LEEDS)
            ->and($order->toArray())->not->toHaveKey('balance_due');
    });
});

it('gives a second till\'s sale, sent by the main till, that till on every child row', function () {
    $result = TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-till.json'));

    expect($result->toPushReply())->toBe(['acknowledgedSeq' => 18247, 'accepted' => 8]);

    foreach (['sales', 'sale_lines', 'sale_payments', 'sale_vats', 'stock_movements'] as $table) {
        expect(DB::table($table)->distinct()->pluck('register_id')->all())->toBe([TillFixtures::TILL_2], $table)
            ->and(DB::table($table)->distinct()->pluck('branch_id')->all())->toBe([TillFixtures::LEEDS], $table);
    }
});

it('keys the second branch by its own branch and seq', function () {
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));
    $result = TillFixtures::apply($this->company, $this->bradford, TillFixtures::sample('push-request.second-branch.json'));

    expect($result->toPushReply())->toBe(['acknowledgedSeq' => 5127, 'accepted' => 8])
        ->and(DB::table('sale_lines')->where('branch_id', TillFixtures::BRADFORD)->pluck('register_id')->unique()->all())->toBe([TillFixtures::BRADFORD_TILL])
        ->and(DB::table('sync_applied_changes')->where('branch_id', TillFixtures::BRADFORD)->count())->toBe(8)
        ->and(DB::table('sync_applied_changes')->where('branch_id', TillFixtures::LEEDS)->count())->toBe(9);
});

it('refuses one branch\'s batch sent by another branch, children included', function () {
    $result = TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-branch.json'));

    expect($result->accepted)->toBe(0)
        ->and($result->acknowledgedSeq)->toBe(5119)
        ->and(collect($result->rejected)->pluck('code')->unique()->values()->all())->toBe(['sync.wrong_branch', 'sync.parent_rejected'])
        ->and(DB::table('sales')->count() + DB::table('sale_lines')->count() + DB::table('stock_movements')->count())->toBe(0);
});

it('applies the rows of pull-reply.json with exact values (company-wide, hub-owned)', function () {
    $result = TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('pull-reply.json')['changes']);

    // WebOrder is a portal-to-till message (contract §12), not a stored till entity.
    expect($result->accepted)->toBe(9)
        ->and($result->acknowledgedSeq)->toBe(0)
        ->and($result->rejected)->toHaveCount(1)
        ->and($result->rejected[0]->code)->toBe('entity.unknown')
        ->and($result->rejected[0]->key)->toBe('WebOrder:01K5VC7N2W000000000000W001:90410');

    ($this->asCompany)(function () {
        $bread = Product::findOrFail('01K5T0Q8C4000000000000P001');
        expect($bread->sell_price)->toBe('1.45')
            ->and($bread->cost_price)->toBe('0.9800')
            ->and($bread->min_stock_qty)->toBe('6.0000')
            ->and($bread->unit_type)->toBe(UnitType::Pcs)
            ->and($bread->age_rule)->toBe(AgeRule::None)
            ->and($bread->negative_stock_mode)->toBeNull()
            ->and($bread->track_stock)->toBeTrue()
            ->and($bread->row_version)->toBe(90405)
            ->and($bread->hub_edited_at)->toBeNull()
            ->and($bread->extra)->toBeNull()
            ->and($bread->productBarcodes()->pluck('barcode')->all())->toBe(['0400001042175']);

        $standard = VatRate::findOrFail('01K5T0Q8C4000000000000V001');
        expect($standard->percentage)->toBe('20.0000')
            ->and($standard->treatment)->toBe(VatTreatment::Apply)
            ->and($standard->effective_from?->format('Y-m-d'))->toBe('2011-01-04')
            ->and($standard->effective_to)->toBeNull()
            ->and(ProductBarcode::query()->count())->toBe(2);
    });
});

it('stores every sample entity with every field exactly as sent', function (string $entity) {
    $payload = TillFixtures::sample("entities/{$entity}.json");
    $result = TillFixtures::apply($this->company, $this->leeds, [TillFixtures::envelope($entity, $payload, 1)]);

    expect($result->rejected)->toBe([])->and($result->accepted)->toBe(1);

    $def = EntityRegistry::get($entity);
    $row = (array) DB::table($def->table)->where('id', $payload['id'])->first();

    foreach ($def->fields as $name => $field) {
        expect(Values::same($field, $row[$field->column], Values::toColumn($field, $payload[$name])))
            ->toBeTrue("{$entity}.{$name}: stored ".var_export($row[$field->column], true).', sent '.json_encode($payload[$name]));
    }

    expect($row['company_id'])->toBe(TillFixtures::COMPANY)
        ->and(Values::same(null, $row['created_at'], Values::dateTime($payload['createdAt'])))->toBeTrue()
        ->and($row['row_version'])->toBe($payload['rowVersion'])
        ->and($row['extra'])->toBeNull();

    if ($def->hasScopeColumn('branch_id')) {
        expect($row['branch_id'])->toBe(TillFixtures::LEEDS);
    }
})->with(array_map(fn ($f) => basename($f, '.json'), glob(__DIR__.'/../../../'.TillFixtures::CONTRACT.'/samples/entities/*.json') ?: []));
