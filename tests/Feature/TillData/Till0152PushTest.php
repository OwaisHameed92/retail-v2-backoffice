<?php

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\Ledger;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\ShopSettings\Actions\SaveShopSettings;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\AccountPayDate;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.52 pack (UPCOMING-CHANGES, the last 7 entries of 2026-10-06): AccountPayDate relayed to every other branch
 * with `reminderSetupKey`, derivedColumns never pulled, the new settings, and the one-time CustomerCreditReclass /
 * CustomerCreditReclassReversal pair. ProductRecallBranchState: tests/Feature/Compliance/ProductRecallTest.php.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-10-06 10:00:00');
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

it('stores a pay date\'s reminderSetupKey as sent and relays the row to every other branch, never back to its own', function () {
    $key = hash('sha256', '07700900123|whatsapp:on');
    $payDate = [...TillFixtures::sample('entities/AccountPayDate.json'), 'reminderSetupKey' => $key, 'lastReminderError' => "WhatsApp not set up\nEmail: no SMTP server.", 'lastTryFailed' => true];

    $this->sync->push([TillFixtures::envelope('AccountPayDate', $payDate, 1)])->assertOk();

    ($this->asCompany)(fn () => expect(AccountPayDate::query()->findOrFail($payDate['id']))
        ->reminder_setup_key->toBe($key)
        ->origin_branch_id->toBe($this->sync->leeds->id));

    expect(collect(Pull::changes($this->sync->pull(0)))->pluck('entity'))->not->toContain('AccountPayDate');

    $reply = $this->sync->pull(0, bradford: true)->assertOk();
    $row = collect(Pull::changes($reply))->firstWhere('entity', 'AccountPayDate');

    expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([])
        ->and($row)->toMatchArray(['op' => 'I', 'branchId' => TillFixtures::BRADFORD])
        ->and($row['payload'])->toEqual($payDate)                                   // the owner's row, unchanged
        ->and($row['payload'])->toMatchArray(['branchId' => TillFixtures::LEEDS, 'isCurrent' => true, 'lastTryFailed' => true]);
});

it('never pulls derivedColumns except the ledger sums: Customer balance and points go, pendingPoints and a recall\'s close members never', function () {
    expect(PullPayload::neverSent('Customer'))->toBe(['pendingPoints'])
        ->and(PullPayload::neverSent('ProductRecall'))->toEqualCanonicalizing(['status', 'closedAt', 'closedByUserId', 'note', 'returnedQty'])
        ->and(PullPayload::omittable('ProductRecall'))->toEqualCanonicalizing(PullPayload::neverSent('ProductRecall'))
        ->and(PullPayload::neverSent('Product'))->toBe([]);
});

it('drops an older till\'s ProductRecall.isOpen (gone from the 0.1.52 schema) instead of keeping it in extra', function () {
    $recall = [...Pull::payload('ProductRecall', '01K5RC00000000000000000001', ['scope' => null]), 'isOpen' => true, 'isDeleted' => false, 'domainEvents' => []];

    $result = TillFixtures::apply($this->company, $this->sync->leeds, [TillFixtures::envelope('ProductRecall', $recall, 1, ['branchId' => TillFixtures::LEEDS])]);

    expect($result->rejected)->toBe([])->and(DB::table('product_recalls')->where('id', $recall['id'])->value('extra'))->toBeNull();
});

it('shares customers.reminders_due_on_till and never stores customers.reminders_from_utc (local only)', function () {
    app(SaveShopSettings::class)->handle($this->company, null, ['customers.reminders_due_on_till' => true]);
    $local = ['scope' => 'branch', 'scopeId' => '', 'key' => 'customers.reminders_from_utc', 'value' => '2026-10-06T09:00:00Z', 'updatedAt' => '2026-10-06T09:00:00Z'];
    $result = TillFixtures::apply($this->company, $this->sync->leeds, [[
        'seq' => 1, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting('branch', '', $local['key']), 'op' => 'I', 'version' => 1,
        'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '', 'at' => $local['updatedAt'],
        'payload' => $local, 'key' => 'Setting:x:1',
    ]]);

    expect($result->count(ChangeOutcome::Skipped))->toBe(1)
        ->and(DB::table('till_settings')->where('company_id', $this->company->id)->pluck('value', 'setting_key')->all())
        ->toBe(['customers.reminders_due_on_till' => 'true']);
});

it('nets a CustomerCreditReclass and its CustomerCreditReclassReversal to nothing: free-text refType, never a refund', function () {
    $refId = TillFixtures::COMPANY.':'.TillFixtures::BRADFORD;
    $entry = fn (string $id, string $refType, string $date) => Pull::payload('JournalEntry', $id, [
        'branchId' => TillFixtures::BRADFORD, 'registerId' => TillFixtures::BRADFORD_TILL, 'date' => $date, 'refType' => $refType, 'refId' => $refId,
        'periodId' => '', 'reversesEntryId' => null, 'memo' => 'Customer credit moved to 2260', 'isReversed' => false, 'reversedByEntryId' => null,
    ]);
    $line = fn (string $id, string $entryId, string $code, float $debit, float $credit) => Pull::payload('JournalLine', $id, [
        'branchId' => TillFixtures::BRADFORD, 'registerId' => '', 'journalEntryId' => $entryId, 'accountCode' => $code, 'debit' => $debit, 'credit' => $credit,
    ]);
    // 0.1.51 moved Bradford's customer credit 1100 → 2260; 0.1.52 puts it back once (only the lowest Branch.id moves it).
    $changes = [
        $entry('01K5VB000000000000JE00RC01', 'CustomerCreditReclass', '2026-10-05'),
        $line('01K5VB000000000000JN00RC11', '01K5VB000000000000JE00RC01', '1100', 42.5, 0.0),
        $line('01K5VB000000000000JN00RC12', '01K5VB000000000000JE00RC01', '2260', 0.0, 42.5),
        $entry('01K5VB000000000000JE00RC02', 'CustomerCreditReclassReversal', '2026-10-06'),
        $line('01K5VB000000000000JN00RC21', '01K5VB000000000000JE00RC02', '2260', 42.5, 0.0),
        $line('01K5VB000000000000JN00RC22', '01K5VB000000000000JE00RC02', '1100', 0.0, 42.5),
    ];
    $entities = ['JournalEntry', 'JournalLine', 'JournalLine', 'JournalEntry', 'JournalLine', 'JournalLine'];
    $result = TillFixtures::apply($this->company, $this->sync->bradford, array_map(
        fn (array $payload, string $entity, int $i) => TillFixtures::envelope($entity, $payload, $i + 1),
        $changes, $entities, array_keys($changes),
    ));

    expect($result->rejected)->toBe([]);

    ($this->asCompany)(function () {
        $october = new AccountsFilters('2026-10-01', '2026-10-31');
        $balances = Ledger::balances($october);

        expect(array_map(fn (array $b) => [$b['bd'], $b['bc']], array_intersect_key($balances, array_flip(['1100', '2260']))))
            ->toBe(['1100' => ['4250', '4250'], '2260' => ['4250', '4250']])
            ->and(DB::query()->fromSub(RefundFix::entries($october), 'f')->count())->toBe(0);
    });
});
