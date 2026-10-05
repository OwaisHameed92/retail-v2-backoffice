<?php

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Enums\AccountPayDateReminderChannel;
use App\Domain\TillData\Enums\CashMovementType;
use App\Domain\TillData\Enums\CustomerTransactionType;
use App\Domain\TillData\Exceptions\ReadOnlyTillRow;
use App\Domain\TillData\Models\AccountPayDate;
use App\Domain\TillData\Models\CashMovement;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\CustomerOrder;
use App\Domain\TillData\Models\CustomerTransaction;
use App\Domain\TillData\Models\JournalEntry;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.27–0.1.51 pack (PORTAL-CHANGES-2026-10-06, the "must" rows of §1): AccountPayDate, customer advances,
 * the new Customer / CustomerOrder fields, ledger account 2260 and refTypes, compliance U / D, Role permissions with
 * `role: null`, the push acknowledgement (§7) and refused rows re-sent under the same id and version.
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
    $this->push = fn (array $changes, bool $bradford = false) => TillFixtures::apply($this->company, $bradford ? $this->bradford : $this->leeds, $changes);
});

/** A branch row of Leeds (till 1) in the till's shape: every schema member, then the overrides. */
function till0151Row(string $entity, string $id, array $overrides = []): array
{
    return Pull::payload($entity, $id, ['branchId' => TillFixtures::LEEDS, ...$overrides]);
}

it('stores AccountPayDate rows read only: a newer pay date stamps the old one (U); derived members are not stored', function () {
    $first = till0151Row('AccountPayDate', '01K5VB0000000000000PD00001', [
        'customerId' => '01K5T0Q8C4000000000000K001', 'saleId' => '', 'dueAt' => '2026-10-10T00:00:00Z', 'note' => "Pays on Friday\nafter work",
        'userId' => '01K5T0Q8C4000000000000A001', 'replacedAt' => null, 'reminderSentAt' => null, 'reminderChannel' => 'none',
        'reminderAttempts' => 2, 'lastReminderAt' => '2026-10-06T09:00:00Z',
        'lastReminderError' => "WhatsApp and Email not set up\nWhatsApp: no gateway.\nEmail: no SMTP server.",
        'isCurrent' => true, 'lastTryFailed' => true,
    ]);
    $replaced = [...$first, 'replacedAt' => '2026-10-07T08:00:00Z', 'isCurrent' => false, 'rowVersion' => 2, 'updatedAt' => '2026-10-07T08:00:00Z'];
    $second = [...$first, 'id' => '01K5VB0000000000000PD00002', 'saleId' => '01K5VB000000000SR001000484', 'dueAt' => '2026-10-17T00:00:00Z',
        'reminderChannel' => 'whatsApp', 'reminderSentAt' => '2026-10-07T09:00:00Z', 'reminderAttempts' => 0, 'lastReminderError' => ''];

    $result = ($this->push)([
        TillFixtures::envelope('AccountPayDate', $first, 1),
        TillFixtures::envelope('AccountPayDate', $replaced, 2, ['op' => 'U']),
        TillFixtures::envelope('AccountPayDate', $second, 3),
    ]);

    expect($result->rejected)->toBe([])->and(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 3, 'accepted' => 3])
        ->and(Schema::getColumnListing('account_pay_dates'))->not->toContain('is_current', 'last_try_failed');

    ($this->asCompany)(function () {
        $old = AccountPayDate::query()->findOrFail('01K5VB0000000000000PD00001');
        $new = AccountPayDate::query()->findOrFail('01K5VB0000000000000PD00002');

        expect($old->replaced_at?->toIso8601ZuluString())->toBe('2026-10-07T08:00:00Z')
            ->and($old->last_reminder_error)->toContain("\nEmail: no SMTP server.")
            ->and($old->branch_id)->toBe(TillFixtures::LEEDS)
            ->and($new->sale_id)->toBe('01K5VB000000000SR001000484')
            ->and($new->reminder_channel)->toBe(AccountPayDateReminderChannel::WhatsApp)
            ->and(fn () => $new->forceFill(['note' => 'changed'])->save())->toThrow(ReadOnlyTillRow::class);
    });
});

it('sums advances into the balance (negative = credit held), stores tender / till / shift, and never trusts the till\'s owed or creditHeld', function () {
    $customer = TillFixtures::sample('entities/Customer.json');
    unset($customer['earnsPoints'], $customer['pendingPoints']); // a till before 0.1.28 / 0.1.32
    $customer = [...$customer, 'balance' => 999, 'owed' => 999, 'creditHeld' => 999];
    $ledger = TillFixtures::sample('entities/CustomerTransaction.json'); // an account sale of £8.40
    $money = fn (string $id, string $type, float $amount, string $tender) => [...$ledger, 'id' => $id, 'type' => $type, 'amount' => $amount,
        'saleId' => '', 'note' => 'Card advance', 'tender' => $tender, 'registerId' => TillFixtures::TILL_1, 'shiftId' => '01K5VB0000000SHR0010000001'];

    $result = ($this->push)([
        TillFixtures::envelope('Customer', $customer, 1),
        TillFixtures::envelope('CustomerTransaction', $ledger, 2),
        TillFixtures::envelope('CustomerTransaction', $money('01K5VB0000000000000CT00482', 'payment', -8.40, 'Cash'), 3),
        TillFixtures::envelope('CustomerTransaction', $money('01K5VB0000000000000CT00483', 'advance', -20.00, 'Card'), 4),
        TillFixtures::envelope('CustomerTransaction', $money('01K5VB0000000000000CT00484', 'advanceRefund', 5.00, 'Cash'), 5),
    ]);
    expect($result->rejected)->toBe([]);

    ($this->asCompany)(function () {
        $aisha = Customer::query()->findOrFail('01K5T0Q8C4000000000000K001');
        $advance = CustomerTransaction::query()->findOrFail('01K5VB0000000000000CT00483');
        $sale = CustomerTransaction::query()->findOrFail('01K5VB0000000000000CT00481');

        expect($aisha->balance)->toBe('-15.00')
            ->and(CustomerLedger::totals($aisha->id)['balance'])->toBe('-15.00')
            ->and($aisha->earns_points)->toBeTrue()
            ->and($aisha->pending_points)->toBe(0)
            ->and(Schema::getColumnListing('customers'))->not->toContain('owed', 'credit_held')
            ->and($aisha->extra ?? [])->not->toHaveKeys(['owed', 'creditHeld'])
            ->and($advance->type)->toBe(CustomerTransactionType::Advance)
            ->and([$advance->tender, $advance->register_id, $advance->shift_id])->toBe(['Card', TillFixtures::TILL_1, '01K5VB0000000SHR0010000001'])
            ->and(CustomerTransaction::query()->findOrFail('01K5VB0000000000000CT00484')->type)->toBe(CustomerTransactionType::AdvanceRefund)
            ->and([$sale->tender, $sale->register_id, $sale->shift_id])->toBe([null, null, null]);
    });

    // A customer who does not collect points stays that way; pendingPoints is stored as sent (EPOS Q1 open).
    $off = [...TillFixtures::sample('entities/Customer.json'), 'earnsPoints' => false, 'pendingPoints' => 12, 'rowVersion' => 2, 'updatedAt' => '2026-09-02T08:00:00Z'];
    expect(($this->push)([TillFixtures::envelope('Customer', $off, 6, ['op' => 'U'])])->rejected)->toBe([]);
    ($this->asCompany)(fn () => expect(Customer::query()->findOrFail('01K5T0Q8C4000000000000K001')->only(['earns_points', 'pending_points', 'balance']))
        ->toBe(['earns_points' => false, 'pending_points' => 12, 'balance' => '-15.00']));
});

it('accepts the cash advance movements and CustomerOrder.customerId', function () {
    $movement = fn (string $id, string $type, float $amount) => till0151Row('CashMovement', $id, [
        'registerId' => TillFixtures::TILL_1, 'shiftId' => '01K5VB0000000SHR0010000001', 'type' => $type, 'amount' => $amount, 'reasonId' => '', 'note' => '',
        'userId' => '01K5T0Q8C4000000000000A001', 'at' => '2026-10-06T10:00:00Z',
    ]);
    $order = [...TillFixtures::sample('entities/CustomerOrder.json'), 'customerId' => '01K5T0Q8C4000000000000K001'];

    $result = ($this->push)([
        TillFixtures::envelope('CashMovement', $movement('01K5VB0000000CMR0010000011', 'customerAdvance', 20.0), 1),
        TillFixtures::envelope('CashMovement', $movement('01K5VB0000000CMR0010000012', 'customerAdvanceRefund', -5.0), 2),
        TillFixtures::envelope('CustomerOrder', $order, 3),
    ]);

    expect($result->rejected)->toBe([]);
    ($this->asCompany)(fn () => expect(CashMovement::query()->orderBy('id')->pluck('type')->all())
        ->toBe([CashMovementType::CustomerAdvance, CashMovementType::CustomerAdvanceRefund])
        ->and(CustomerOrder::query()->findOrFail($order['id'])->customer_id)->toBe('01K5T0Q8C4000000000000K001'));
});

it('groups account 2260 by code across shops and accepts the new journal refTypes; an advance refund is not a sales refund', function () {
    $account = fn (string $id) => Pull::payload('Account', $id, ['code' => '2260', 'name' => 'Customer account credit', 'type' => 'liability', 'parentCode' => null, 'vatBox' => null, 'isActive' => true]);
    expect(($this->push)([TillFixtures::envelope('Account', $account('01K5VB00000000000ACC226001'), 1)])->rejected)->toBe([])
        ->and(($this->push)([TillFixtures::envelope('Account', $account('01K5VB00000000000ACC226002'), 1)], bradford: true)->rejected)->toBe([]);

    $entry = fn (string $id, string $refType, string $refId) => till0151Row('JournalEntry', $id, [
        'registerId' => TillFixtures::TILL_1, 'date' => '2026-10-01', 'refType' => $refType, 'refId' => $refId, 'periodId' => '', 'reversesEntryId' => null,
        'memo' => 'Advance refunded — late posting, dated 28/09/2026', 'isReversed' => false, 'reversedByEntryId' => null,
    ]);
    $line = fn (string $id, string $entryId, string $code, float $debit, float $credit) => till0151Row('JournalLine', $id, [
        'registerId' => '', 'journalEntryId' => $entryId, 'accountCode' => $code, 'debit' => $debit, 'credit' => $credit,
    ]);
    $types = ['CustomerAdvanceRefund', 'CustomerAccountMove', 'CustomerCreditReclass', 'CustomerOpening'];
    $changes = [];

    foreach ($types as $i => $type) {
        $id = '01K5VB000000000000JE00000'.($i + 1);
        $changes[] = TillFixtures::envelope('JournalEntry', $entry($id, $type, $type === 'CustomerCreditReclass' ? TillFixtures::COMPANY.':'.TillFixtures::LEEDS : '01K5VB0000000000000CT00484'), $i * 3 + 11);
        $changes[] = TillFixtures::envelope('JournalLine', $line('01K5VB000000000000JN0000'.($i + 1).'1', $id, '2260', 5.0, 0.0), $i * 3 + 12);
        $changes[] = TillFixtures::envelope('JournalLine', $line('01K5VB000000000000JN0000'.($i + 1).'2', $id, '1200', 0.0, 5.0), $i * 3 + 13);
    }

    expect(($this->push)($changes)->rejected)->toBe([]);

    ($this->asCompany)(function () use ($types) {
        $chart = AccountChart::byCode();

        expect($chart['2260'])->toMatchArray(['name' => 'Customer account credit', 'type' => 'liability', 'shops' => 2])
            ->and(JournalEntry::query()->orderBy('id')->pluck('ref_type')->all())->toBe($types)
            ->and(DB::query()->fromSub(RefundFix::entries(new AccountsFilters('2026-10-01', '2026-10-31')), 'f')->count())->toBe(0);
    });
});

it('applies updates and soft deletes on the compliance tables and supplier invoices', function (string $entity) {
    $row = till0151Row($entity, '01K5VB0000000000000CMP0001', ['updatedAt' => '2026-10-06T09:00:00Z']);
    $member = collect(EntityRegistry::get($entity)->fields)->first(fn ($f) => in_array($f->type, ['string', 'text'], true) && ! str_ends_with($f->name, 'Id') && ! $f->nullable);
    $updated = [...$row, $member->name => 'changed', 'rowVersion' => 2, 'updatedAt' => '2026-10-06T10:00:00Z'];
    $deleted = [...$updated, 'rowVersion' => 3, 'updatedAt' => '2026-10-06T11:00:00Z', 'deletedAt' => '2026-10-06T11:00:00Z', 'isDeleted' => true];

    $result = ($this->push)([
        TillFixtures::envelope($entity, $row, 1),
        TillFixtures::envelope($entity, $updated, 2, ['op' => 'U']),
    ]);
    expect($result->rejected)->toBe([])->and($result->count(ChangeOutcome::Applied))->toBe(2);

    $table = EntityRegistry::get($entity)->table;
    expect(DB::table($table)->where('id', $row['id'])->value($member->column))->toBe('changed');

    $result = ($this->push)([TillFixtures::envelope($entity, $deleted, 3, ['op' => 'D'])]);
    expect($result->rejected)->toBe([])
        ->and(DB::table($table)->where('id', $row['id'])->value('deleted_at'))->not->toBeNull();
    ($this->asCompany)(fn () => expect((EntityRegistry::get($entity)->model)::query()->whereKey($row['id'])->exists())->toBeFalse());
})->with(['ComplianceLicence', 'TrainingRecord', 'IncidentReport', 'DiaryCheckDefinition', 'DiaryCheckRecord', 'TemperatureUnit', 'SupplierInvoice']);

it('accepts a Role whose permissions carry role: null (till 0.1.31)', function () {
    $role = Pull::payload('Role', '01K5T0Q8C4000000000000RE01', ['name' => 'Supervisor', 'permissions' => [
        ['roleId' => '01K5T0Q8C4000000000000RE01', 'permissionKey' => 'analytics.view', 'role' => null],
        ['roleId' => '01K5T0Q8C4000000000000RE01', 'permissionKey' => 'compliance.record', 'role' => null],
    ]]);

    $result = ($this->push)([TillFixtures::envelope('Role', $role, 1)]);

    expect($result->rejected)->toBe([])
        ->and(json_decode((string) DB::table('till_roles')->where('id', $role['id'])->value('permissions'), true))->toHaveCount(2);
});

it('acknowledges a batch whose rows were skipped or stored up to its last seq, never below its first (§7, till 0.1.34)', function () {
    $own = ['id' => '01M41S74PBG69ACDTWJ7F1PY7D', 'companyId' => TillFixtures::COMPANY, 'handlerName' => 'StockProjection', 'lastSeq' => 9,
        'createdAt' => '2026-10-03T21:00:00Z', 'updatedAt' => '2026-10-03T21:40:00Z', 'rowVersion' => 1, 'deletedAt' => null];
    $blank = [...$own, 'id' => '01M41S74PBG69ACDTWJ7F1PY7E', 'companyId' => ''];
    $grid = ['scope' => 'company', 'scopeId' => TillFixtures::COMPANY, 'key' => 'grid.layout.products.01K5T0Q8C4000000000000A001', 'value' => '{}',
        'type' => 'json', 'updatedAt' => '2026-10-03T21:40:00Z', 'id' => '01K5T0Q8C4000000000000S001', 'companyId' => TillFixtures::COMPANY];

    $result = ($this->push)([
        TillFixtures::envelope('EventSubscription', $own, 41),
        TillFixtures::envelope('EventSubscription', $blank, 42, ['companyId' => '']),
        TillFixtures::envelope('Setting', $grid, 43, ['companyId' => '01K5T0Q8C4000000000000A001']),
        TillFixtures::envelope('SomethingNewer', [...$own, 'id' => '01M41S74PBG69ACDTWJ7F1PY7F'], 44),
        TillFixtures::envelope('CustomerTransaction', TillFixtures::sample('entities/CustomerTransaction.json'), 45),
    ]);

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 45, 'accepted' => 5])
        ->and($result->count(ChangeOutcome::Skipped))->toBe(3)
        ->and(DB::table('event_subscriptions')->count())->toBe(0)
        ->and(DB::table('till_settings')->count())->toBe(0);

    // Only skipped rows: still acknowledged in full, so the till's queue moves on.
    expect(TillFixtures::ack(($this->push)([TillFixtures::envelope('EventSubscription', [...$own, 'rowVersion' => 2], 46, ['version' => 2])])))
        ->toBe(['acknowledgedSeq' => 46, 'accepted' => 1]);
});

it('accepts a row it refused for a blank companyId when the till re-sends it under the same id and version (till 0.1.42)', function () {
    $layer = till0151Row('FifoStockLayer', '01K5VB0000000000000FS00001');

    $refused = ($this->push)([TillFixtures::envelope('FifoStockLayer', [...$layer, 'companyId' => ''], 7, ['companyId' => ''])]);
    expect(collect($refused->rejected)->pluck('code')->all())->toBe(['change.invalid'])
        ->and(TillFixtures::ack($refused)['acknowledgedSeq'])->toBe(6);

    $resent = ($this->push)([TillFixtures::envelope('FifoStockLayer', $layer, 7)]);
    expect($resent->rejected)->toBe([])->and($resent->count(ChangeOutcome::Applied))->toBe(1)
        ->and(TillFixtures::ack($resent))->toBe(['acknowledgedSeq' => 7, 'accepted' => 1])
        ->and(DB::table('fifo_stock_layers')->where('id', $layer['id'])->value('company_id'))->toBe(TillFixtures::COMPANY);
});
