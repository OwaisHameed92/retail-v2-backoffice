<?php

use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\ApplySyncChanges;
use App\Domain\TillData\Actions\RecomputeCustomerBalances;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.9B: relayed branch rows (contract v1.4.1 §10.2, §19.2, §19.4 test 12, §21.4). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->valid = fn ($reply) => expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $this->relay = TillFixtures::sample('pull-reply.relay.json')['changes'];
    // The relay sample's rows as Leeds pushed them (the owning branch, its own seq and row versions).
    $this->leedsPush = fn (array $rows, int $firstSeq = 1) => $this->sync->push(array_map(
        fn (array $row, int $n) => TillFixtures::envelope($row['entity'], $row['payload'], $firstSeq + $n, ['branchId' => $row['payload']['branchId']]),
        $rows,
        array_keys($rows),
    ))->assertOk();
    $this->transfer = fn (array $overrides = []) => [...$this->relay[0]['payload'], ...$overrides];
    $this->receipt = fn (string $id, array $overrides = []) => Pull::payload('StockTransferReceipt', $id, [
        'transferId' => '01K5VB0000000000000TR00012', 'status' => 'received', 'receivedAt' => '2026-09-24T09:00:00Z',
        'branchId' => TillFixtures::BRADFORD, 'closedAt' => null, ...$overrides,
    ]);
});

test('replays pull-reply.relay.json: Bradford receives Leeds\'s dispatched transfer, its lines and a ledger row; Leeds never gets them back', function () {
    ($this->leedsPush)($this->relay);

    expect(Pull::changes($this->sync->pull(0)))->toBe([]);

    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    ($this->valid)($bradford);
    $changes = Pull::changes($bradford);

    expect(array_column($changes, 'entityId'))->toBe(array_column($this->relay, 'entityId'))
        ->and(array_column($changes, 'version'))->toBe([1, 2, 3, 4]);

    foreach ($changes as $i => $change) {
        expect($change['payload'])->toEqual($this->relay[$i]['payload'])
            ->and($change['branchId'])->toBe(TillFixtures::BRADFORD)                 // addressed to the receiving branch
            ->and($change['payload']['branchId'])->toBe(TillFixtures::LEEDS)         // the owner is unchanged
            ->and($change['op'])->toBe('I')
            ->and($change['companyId'])->toBe(TillFixtures::COMPANY)
            ->and([$change['seq'], $change['registerId']])->toBe([0, '']);
    }

    // The relay sent twice is still one transfer: the same versions again, nothing after them.
    expect(Pull::summary($this->sync->pull(0, bradford: true)))->toBe(Pull::summary($bradford))
        ->and(Pull::changes($this->sync->pull(4, bradford: true)))->toBe([]);

    // Leeds's retry is a duplicate: no new version for anyone.
    ($this->leedsPush)($this->relay);
    expect(Pull::changes($this->sync->pull(4, bradford: true)))->toBe([]);
});

test('a transfer is relayed only once dispatched, every line straight after it; never while requested or when cancelled', function () {
    $requested = ($this->transfer)(['status' => 'requested', 'rowVersion' => 1]);
    ($this->leedsPush)([['entity' => 'StockTransfer', 'payload' => $requested], $this->relay[1], $this->relay[2]]);

    expect(Pull::changes($this->sync->pull(0, bradford: true)))->toBe([]);

    // Dispatched later; the lines are not pushed again. They still follow the header.
    ($this->leedsPush)([['entity' => 'StockTransfer', 'payload' => ($this->transfer)(['rowVersion' => 3])]], 4);
    $bradford = Pull::summary($this->sync->pull(0, bradford: true));

    expect(array_column($bradford, 0))->toBe(['StockTransfer', 'StockTransferLine', 'StockTransferLine'])
        ->and($bradford[1][2])->toBeGreaterThan($bradford[0][2])
        ->and($bradford[2][2])->toBe($bradford[1][2] + 1);

    $cancelled = ($this->transfer)(['id' => '01K5VB0000000000000TR00013', 'reference' => 'TR-LDS-00013', 'status' => 'cancelled']);
    ($this->leedsPush)([['entity' => 'StockTransfer', 'payload' => $cancelled]], 5);
    expect(collect(Pull::changes($this->sync->pull(0, bradford: true)))->pluck('entityId'))->not->toContain('01K5VB0000000000000TR00013');

    // A transfer to another shop is not Bradford's.
    $elsewhere = ($this->transfer)(['id' => '01K5VB0000000000000TR00014', 'toBranchId' => TillFixtures::LEEDS]);
    ($this->leedsPush)([['entity' => 'StockTransfer', 'payload' => $elsewhere]], 6);
    expect(collect(Pull::changes($this->sync->pull(0, bradford: true)))->pluck('entityId'))->not->toContain('01K5VB0000000000000TR00014')
        ->and(Pull::changes($this->sync->pull(0)))->toBe([]);
});

test('a receipt goes back to the sending shop (I), and again when it moves on (U); never to the shop that received', function () {
    ($this->leedsPush)(array_slice($this->relay, 0, 3));
    $receipt = ($this->receipt)('01K5VB000000000000TRC00012');
    $line = Pull::payload('StockTransferReceiptLine', '01K5VB00000000000TRCN00121', [
        'receiptId' => $receipt['id'], 'transferId' => $receipt['transferId'], 'transferLineId' => '01K5VB000000000000TRN00121',
        'branchId' => TillFixtures::BRADFORD,
    ]);
    $this->sync->push([
        TillFixtures::envelope('StockTransferReceipt', $receipt, 1, ['branchId' => TillFixtures::BRADFORD]),
        TillFixtures::envelope('StockTransferReceiptLine', $line, 2, ['branchId' => TillFixtures::BRADFORD]),
    ], bradford: true)->assertOk()->assertJsonPath('accepted', 2);

    $leeds = $this->sync->pull(0)->assertOk();
    ($this->valid)($leeds);

    expect(array_map(fn ($c) => [$c['entity'], $c['op'], $c['branchId'], $c['payload']['branchId']], Pull::changes($leeds)))->toBe([
        ['StockTransferReceipt', 'I', TillFixtures::LEEDS, TillFixtures::BRADFORD],
        ['StockTransferReceiptLine', 'I', TillFixtures::LEEDS, TillFixtures::BRADFORD],
    ])->and(collect(Pull::changes($this->sync->pull(0, bradford: true)))->pluck('entity')->unique()->values()->all())
        ->toBe(['StockTransfer', 'StockTransferLine']);

    $since = $leeds->json('highestVersion');
    $closed = [...$receipt, 'status' => 'closed', 'closedAt' => '2026-09-24T10:00:00Z', 'rowVersion' => 2, 'updatedAt' => '2026-09-24T10:00:00Z'];
    $this->sync->push([TillFixtures::envelope('StockTransferReceipt', $closed, 3, ['branchId' => TillFixtures::BRADFORD, 'op' => 'U'])], bradford: true)->assertOk();

    $moved = Pull::changes($this->sync->pull($since));
    expect($moved[0]['entity'])->toBe('StockTransferReceipt')
        ->and($moved[0]['op'])->toBe('U')
        ->and($moved[0]['payload']['status'])->toBe('closed');
});

test('§10.1 and 19.4 test 11: an account sale at Leeds and a payment at Bradford move the balance once, everywhere', function () {
    Pull::portalCreate($this->company, 'Customer', TillFixtures::sample('entities/Customer.json'));
    $charge = $this->relay[3]['payload'];
    $payment = [...$charge, 'id' => '01K5VB0000000000000CT00482', 'type' => 'payment', 'amount' => -5.00, 'saleId' => '',
        'balanceAfter' => -5.00, 'note' => 'Payment', 'branchId' => TillFixtures::BRADFORD];

    ($this->leedsPush)([$this->relay[3]]);
    $this->sync->push([TillFixtures::envelope('CustomerTransaction', $payment, 1)], bradford: true)->assertOk();

    $customer = fn () => DB::table('customers')->where('id', '01K5T0Q8C4000000000000K001')->first();
    expect(Money::normalise($customer()->balance))->toBe('3.40')->and((int) $customer()->points)->toBe(0);

    // Each till receives the other's row only: its own sum + the relayed one = the portal's £3.40.
    $leeds = collect(Pull::changes($this->sync->pull(0)))->where('entity', 'CustomerTransaction')->values();
    $bradford = collect(Pull::changes($this->sync->pull(0, bradford: true)))->where('entity', 'CustomerTransaction')->values();
    expect($leeds->pluck('entityId')->all())->toBe(['01K5VB0000000000000CT00482'])
        ->and($bradford->pluck('entityId')->all())->toBe(['01K5VB0000000000000CT00481'])
        ->and(8.40 + $leeds[0]['payload']['amount'])->toEqualWithDelta(3.40, 0.001)
        ->and(collect(Pull::changes($this->sync->pull(0)))->firstWhere('entity', 'Customer')['payload']['balance'])->toBe(3.4);

    // A Customer row pushed with the till's own cached figures is not the truth, and not an edit either.
    $cached = [...TillFixtures::sample('entities/Customer.json'), 'balance' => 99.99, 'points' => 5000, 'rowVersion' => 2];
    $reply = $this->sync->push([TillFixtures::envelope('Customer', $cached, 2, ['op' => 'U'])])->assertOk();
    expect($reply->json('accepted'))->toBe(1)
        ->and(Money::normalise($customer()->balance))->toBe('3.40')
        ->and(DB::table('sync_conflicts')->count())->toBe(0);

    // A withdrawn ledger row no longer counts; the pull sends the portal's own sum (the till ignores it).
    $withdrawn = [...$payment, 'deletedAt' => '2026-09-29T10:00:00Z', 'rowVersion' => 2];
    $this->sync->push([TillFixtures::envelope('CustomerTransaction', $withdrawn, 2, ['op' => 'D'])], bradford: true)->assertOk();
    expect(Money::normalise($customer()->balance))->toBe('8.40');
});

test('a full recompute puts every customer back to the ledger\'s sum', function () {
    Pull::portalCreate($this->company, 'Customer', TillFixtures::sample('entities/Customer.json'));
    ($this->leedsPush)([$this->relay[3]]);
    DB::table('customers')->update(['balance' => '12.00', 'points' => 7]);

    expect(app(RecomputeCustomerBalances::class)->handle($this->company->id))->toBe(1)
        ->and(Money::normalise(DB::table('customers')->value('balance')))->toBe('8.40');
});

test('tenant isolation: another business\'s transfers and ledger rows are never relayed here', function () {
    $other = Company::factory()->create();
    $a = Branch::factory()->forCompany($other)->create(['code' => 'AAA']);
    $row = [...$this->relay[3]['payload'], 'id' => '01K5VB0000000000000CT00999', 'companyId' => $other->id, 'branchId' => $a->id];
    app(ApplySyncChanges::class)->handle($other, $a, [TillFixtures::envelope('CustomerTransaction', $row, 1)]);
    app(HubVersions::class)->stampPending($other->id);

    expect(Pull::changes($this->sync->pull(0, bradford: true)))->toBe([])
        ->and(Pull::changes($this->sync->pull(0)))->toBe([]);
});
