<?php

use App\Domain\TillData\Enums\CustomerOrderSource;
use App\Domain\TillData\Enums\DispensingRecordExemption;
use App\Domain\TillData\Enums\DispensingRecordExemptionEvidence;
use App\Domain\TillData\Enums\PaymentAttemptStatus;
use App\Domain\TillData\Models\CustomerOrder;
use App\Domain\TillData\Models\DispensingItem;
use App\Domain\TillData\Models\DispensingRecord;
use App\Domain\TillData\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\Till0185Rows as Rows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * Till 0.1.85 (W1; docs/contracts/portal-api-0.1.85, PORTAL-CHANGES-pharmacy.md, WORKSHOP-BIKEBOOK.md): every row a
 * pharmacy or a BikeBook shop now pushes goes through the real `POST sync/push` and is stored, never refused (a 422
 * would stop that shop's queue). Each envelope is first checked against the 0.1.85 schemas.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->travelTo('2026-11-08 10:00:00');
    $this->push = function (array $changes) {
        foreach ($changes as $i => $change) {
            expect(ContractSchema::changeErrors($change, "changes[{$i}]"))->toBe([]);
        }

        $reply = $this->sync->push($changes)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([])
            ->and($reply->json('accepted'))->toBe(count($changes))
            ->and($reply->json('acknowledgedSeq'))->toBe(max(array_column($changes, 'seq')));

        return $reply;
    };
    $this->row = fn (string $table, string $id) => (array) DB::table($table)->where('id', $id)->first();
});

it('stores a 0.1.85 DispensingRecord with every new member, a new exemption and one the portal does not know yet', function () {
    $new = ['prescriberAddress' => "Riverside Surgery\n12 Kirkgate, Leeds LS1 6BR, a long address that goes past two hundred and fifty five characters "
        .str_repeat('so that a varchar column would refuse it ', 6), 'exemptionEvidence' => 'seen', 'saleLinePosition' => 3,
        'chargeSettledAt' => '2026-11-08T09:30:00Z', 'chargeRefunded' => 9.90, 'chargeKept' => 0];

    ($this->push)([
        Rows::envelope('DispensingRecord', '01K9PH000000000000000DR001', 1, [...$new, 'exemption' => 'universalCredit']),
        Rows::envelope('DispensingRecord', '01K9PH000000000000000DR002', 2, ['exemption' => 'freeInNation', 'exemptionEvidence' => 'notNeeded']),
        Rows::envelope('DispensingItem', '01K9PH000000000000000DX001', 3, ['dispensingRecordId' => '01K9PH000000000000000DR001', 'freeOfChargeReason' => 'hrtPrepaymentCertificate']),
    ]);
    // A value a later till adds (the schema's enum does not list it): stored as sent, never a 422 (§17.11).
    $later = Rows::envelope('DispensingRecord', '01K9PH000000000000000DR003', 4, ['exemption' => 'someLaterExemption', 'exemptionEvidence' => 'someLaterEvidence', 'futureMember' => ['a' => 1]]);
    $this->sync->push([$later])->assertOk()->assertJson(['acknowledgedSeq' => 4, 'accepted' => 1]);

    $record = DispensingRecord::withoutCompanyScope()->findOrFail('01K9PH000000000000000DR001');
    expect($record->exemption)->toBe(DispensingRecordExemption::UniversalCredit)
        ->and($record->exemption_evidence)->toBe(DispensingRecordExemptionEvidence::Seen)
        ->and($record->prescriber_address)->toBe($new['prescriberAddress'])
        ->and($record->sale_line_position)->toBe(3)
        ->and($record->charge_settled_at?->toIso8601ZuluString())->toBe('2026-11-08T09:30:00Z')
        ->and([$record->charge_refunded, $record->charge_kept])->toBe(['9.90', '0.00'])
        ->and($record->extra)->toBeNull()
        ->and($record->branch_id)->toBe($this->sync->leeds->id)
        ->and(DispensingItem::withoutCompanyScope()->findOrFail('01K9PH000000000000000DX001')->free_of_charge_reason?->value)->toBe('hrtPrepaymentCertificate')
        ->and(DispensingRecordExemption::cases())->toHaveCount(22);

    $raw = ($this->row)('dispensing_records', '01K9PH000000000000000DR003');
    expect([$raw['exemption'], $raw['exemption_evidence']])->toBe(['someLaterExemption', 'someLaterEvidence'])
        ->and(json_decode((string) $raw['extra'], true))->toBe(['futureMember' => ['a' => 1]]);
});

it('reads a DispensingRecord from a till before 0.1.85 as notRecorded, blank, 0 and null, and an older order as from the till', function () {
    $old = Rows::payload('DispensingRecord', '01K9PH000000000000000DR009', ['exemption' => 'none']);
    unset($old['prescriberAddress'], $old['exemptionEvidence'], $old['saleLinePosition'], $old['chargeSettledAt'], $old['chargeRefunded'], $old['chargeKept']);

    $item = Rows::payload('DispensingItem', '01K9PH000000000000000DX009', ['dispensingRecordId' => '01K9PH000000000000000DR009']);
    $order = Rows::payload('CustomerOrder', '01K9WS000000000000000CQ009', ['status' => 'ordered']);
    unset($item['freeOfChargeReason'], $order['source'], $order['externalProvider'], $order['externalOrderId'], $order['externalReference']);

    $this->sync->push([TillFixtures::envelope('DispensingRecord', $old, 1), TillFixtures::envelope('DispensingItem', $item, 2), TillFixtures::envelope('CustomerOrder', $order, 3)])->assertOk();

    $raw = ($this->row)('dispensing_records', '01K9PH000000000000000DR009');
    expect([$raw['prescriber_address'], $raw['exemption_evidence'], (int) $raw['sale_line_position'], $raw['charge_settled_at'], (float) $raw['charge_refunded']])->toBe(['', 'notRecorded', 0, null, 0.0])
        ->and($raw['extra'])->toBeNull()
        ->and(($this->row)('dispensing_items', '01K9PH000000000000000DX009')['free_of_charge_reason'])->toBe('none')
        ->and(($this->row)('customer_orders', '01K9WS000000000000000CQ009'))->toMatchArray(['source' => 'till', 'external_provider' => null, 'external_order_id' => null, 'external_reference' => '']);
});

it('keeps a MedicineClassification member the schema does not list in extra', function () {
    ($this->push)([Rows::envelope('MedicineClassification', '01K9PH000000000000000MC001', 1, ['productId' => '01K5T0Q8C4000000000000P001', 'class' => 'pharmacyOnly'])]);
    $this->sync->push([Rows::envelope('MedicineClassification', '01K9PH000000000000000MC001', 2, ['productId' => '01K5T0Q8C4000000000000P001', 'class' => 'prescriptionOnly', 'rowVersion' => 2, 'dmdCode' => '322236009'])])->assertOk();

    $raw = ($this->row)('medicine_classifications', '01K9PH000000000000000MC001');
    expect($raw['class'])->toBe('prescriptionOnly')->and(json_decode((string) $raw['extra'], true))->toBe(['dmdCode' => '322236009']);
});

it('stores a BikeBook workshop order with source, provider, ids and its WS- reference', function () {
    ($this->push)([
        Rows::envelope('CustomerOrder', '01K9WS000000000000000CQ001', 1, [
            'reference' => 'WS-123', 'status' => 'ordered', 'source' => 'workshop', 'externalProvider' => 'bikebook',
            'externalOrderId' => '7f3c2a10-5b9e-4c1d-9a0e-2f6b8d4e1c33', 'externalReference' => 'Job 123 · Trek FX3 service',
        ]),
        Rows::envelope('CustomerOrder', '01K9WS000000000000000CQ002', 2, ['reference' => 'CO-1-1-9', 'status' => 'ordered', 'source' => 'till', 'externalProvider' => null, 'externalOrderId' => null, 'externalReference' => '']),
    ]);

    $order = CustomerOrder::withoutCompanyScope()->findOrFail('01K9WS000000000000000CQ001');
    expect($order->reference)->toBe('WS-123')
        ->and($order->source)->toBe(CustomerOrderSource::Workshop)
        ->and([$order->external_provider, $order->external_order_id, $order->external_reference])->toBe(['bikebook', '7f3c2a10-5b9e-4c1d-9a0e-2f6b8d4e1c33', 'Job 123 · Trek FX3 service'])
        ->and(CustomerOrder::withoutCompanyScope()->findOrFail('01K9WS000000000000000CQ002')->source)->toBe(CustomerOrderSource::Till);
});

it('stores the pharmacy and BikeBook accounts, the Paid in BikeBook tender and a PpcDeduction journal', function () {
    $account = fn (string $id, string $code, string $name, string $type, bool $debit, int $seq) => Rows::envelope('Account', $id, $seq, [
        'code' => $code, 'name' => $name, 'type' => $type, 'parentCode' => null, 'vatBox' => null, 'isSystem' => true, 'isActive' => true, 'isDebitBalance' => $debit,
    ]);
    $refId = TillFixtures::LEEDS.':2026-10';

    ($this->push)([
        $account('01K9AC000000000000000A2270', '2270', 'PPC money owed to NHSBSA', 'liability', false, 1),
        $account('01K9AC000000000000000A4050', '4050', 'NHS prescription charges', 'income', false, 2),
        $account('01K9AC000000000000000A1260', '1260', 'Money held by BikeBook', 'asset', true, 3),
        Rows::envelope('PaymentType', '01K9PT000000000000000PT007', 4, ['name' => 'Paid in BikeBook', 'position' => 7, 'opensDrawer' => false, 'showOnPayment' => true, 'isActive' => true]),
        Rows::envelope('JournalEntry', '01K9JE000000000000000PPC01', 5, ['refType' => 'PpcDeduction', 'refId' => $refId, 'memo' => 'PPC deduction October 2026', 'totalDebits' => 120.6, 'totalCredits' => 120.6]),
        Rows::envelope('JournalLine', '01K9JN000000000000000PPC01', 6, ['journalEntryId' => '01K9JE000000000000000PPC01', 'accountId' => '01K9AC000000000000000A2270', 'accountCode' => '2270', 'debit' => 120.6, 'credit' => 0, 'vatRateId' => null]),
        Rows::envelope('JournalLine', '01K9JN000000000000000PPC02', 7, ['journalEntryId' => '01K9JE000000000000000PPC01', 'accountId' => '01K9AC000000000000000A1230', 'accountCode' => '1230', 'debit' => 0, 'credit' => 120.6, 'vatRateId' => null]),
    ]);

    expect(DB::table('ledger_accounts')->orderBy('code')->get(['code', 'type', 'vat_box'])->map(fn ($a) => [$a->code, $a->type, $a->vat_box])->all())
        ->toBe([['1260', 'asset', null], ['2270', 'liability', null], ['4050', 'income', null]])
        ->and(($this->row)('payment_types', '01K9PT000000000000000PT007'))->toMatchArray(['name' => 'Paid in BikeBook', 'is_cash' => 0, 'opens_drawer' => 0])
        ->and(($this->row)('journal_entries', '01K9JE000000000000000PPC01'))->toMatchArray(['ref_type' => 'PpcDeduction', 'ref_id' => $refId])
        ->and(DB::table('journal_lines')->where('journal_entry_id', '01K9JE000000000000000PPC01')->count())->toBe(2);
});

it('stores StockNotEntered movements and settledOutside payment attempts with onRecordAt', function () {
    ($this->push)([
        Rows::envelope('StockMovement', '01K9SM000000000000000SNE01', 1, ['productId' => '01K5T0Q8C4000000000000P001', 'type' => 'adjustment', 'qtyDelta' => 3, 'qtyBefore' => -3, 'qtyAfter' => 0, 'refType' => 'StockNotEntered', 'reasonId' => null]),
        Rows::envelope('PaymentAttempt', '01K9PA000000000000000SQ001', 2, ['status' => 'settledOutside', 'onRecordAt' => '2026-11-08T09:15:00Z', 'resolutionReason' => 'Refunded on the card machine']),
    ]);

    $attempt = PaymentAttempt::withoutCompanyScope()->findOrFail('01K9PA000000000000000SQ001');
    expect(($this->row)('stock_movements', '01K9SM000000000000000SNE01')['ref_type'])->toBe('StockNotEntered')
        ->and($attempt->status)->toBe(PaymentAttemptStatus::SettledOutside)
        ->and($attempt->on_record_at?->toIso8601ZuluString())->toBe('2026-11-08T09:15:00Z');
});

it('accepts an entity the portal does not know, and acknowledges the local pharmacy and workshop tables without storing them', function () {
    $unknown = ['seq' => 1, 'entity' => 'WorkshopSomethingNew', 'entityId' => '01K9XN000000000000000XN001', 'op' => 'I', 'version' => 1,
        'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '', 'at' => '2026-11-08T09:00:00Z',
        'payload' => ['id' => '01K9XN000000000000000XN001', 'companyId' => TillFixtures::COMPANY, 'note' => 'from a later till'], 'key' => 'WorkshopSomethingNew:1'];
    // Health data never leaves the till; should an older or faulty till send a row of a local table, nothing is kept.
    $patient = [...$unknown, 'seq' => 2, 'entity' => 'PharmacyPatient', 'entityId' => '01K9XN000000000000000PP001', 'payload' => ['id' => '01K9XN000000000000000PP001', 'companyId' => TillFixtures::COMPANY, 'nhsNumber' => '9434765919'], 'key' => 'PharmacyPatient:1'];
    $workshop = [...$patient, 'seq' => 3, 'entity' => 'WorkshopOrder', 'entityId' => '01K9XN000000000000000WQ001', 'payload' => ['id' => '01K9XN000000000000000WQ001', 'companyId' => TillFixtures::COMPANY], 'key' => 'WorkshopOrder:1'];

    $this->sync->push([$unknown, $patient, $workshop])->assertOk()->assertJson(['acknowledgedSeq' => 3, 'accepted' => 3]);

    expect(DB::table('till_unknown_rows')->pluck('entity')->all())->toBe(['WorkshopSomethingNew'])
        ->and((string) json_encode(DB::table('till_unknown_rows')->get()))->not->toContain('9434765919');
});

it('never stores a local-only pharmacy or BikeBook setting, and stores the shared pharmacy and workshop ones', function () {
    $this->sync->push([
        Rows::setting('pharmacy.dmd_trud_api_key', 'trud-secret-123', 1),
        Rows::setting('pharmacy.dmd_server_client_id', 'client-id-abc', 2),
        Rows::setting('pharmacy.dmd_server_client_secret', 'client-secret-xyz', 3),
        Rows::setting('pharmacy.dmd_source', 'portal', 4),
        Rows::setting('pharmacy.dmd_problem', 'none', 5),
        Rows::setting('workshop.bikebook_api_key', 'bb-live-key-999', 6),
        Rows::setting('pharmacy.ods_code', 'FA123', 7),
        Rows::setting('workshop.connection_status', 'connected', 8),
        Rows::setting('vertical.pharmacy', 'true', 9),
    ])->assertOk()->assertJson(['acknowledgedSeq' => 9, 'accepted' => 9]);

    $stored = DB::table('till_settings')->pluck('value', 'setting_key')->all();
    ksort($stored);
    expect($stored)->toBe(['pharmacy.ods_code' => 'FA123', 'vertical.pharmacy' => 'true', 'workshop.connection_status' => 'connected'])
        ->and((string) json_encode(DB::table('sync_conflicts')->get()))->not->toContain('secret')
        ->and((string) json_encode(DB::table('till_unknown_rows')->get()))->not->toContain('bb-live-key-999');
});
