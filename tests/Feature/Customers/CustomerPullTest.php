<?php

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** Module 4.4: portal customer edits reach every till's pull; ledger rows pushed by the tills drive the screens. */
beforeEach(function () {
    $this->travelTo('2026-10-24 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->customer = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)))
        ->where('entity', 'Customer')->values()->all();
});

test('a new customer and an edit are pulled by every till, schema-valid, with the ledger sum as balance', function () {
    $aisha = app(SaveCustomer::class)->handle($this->company, null, ['name' => 'Aisha Rahman', 'card_no' => 'LC000123', 'credit_limit' => '50']);

    foreach ([false, true] as $bradford) {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
        $row = collect(Pull::changes($reply))->firstWhere('entityId', $aisha->id);
        expect($row['op'])->toBe('I')->and($row['branchId'])->toBe('')
            ->and($row['payload'])->toMatchArray(['name' => 'Aisha Rahman', 'cardNo' => 'LC000123', 'phone' => '', 'rowVersion' => 1, 'isActive' => true]);
    }

    $this->travel(1)->minutes();
    app(SaveCustomer::class)->handle($this->company, $aisha->id, ['name' => 'Aisha Khan', 'tier' => 'Gold']);
    $row = collect(($this->customer)(true))->firstWhere('entityId', $aisha->id);
    expect($row['op'])->toBe('U')->and($row['payload'])->toMatchArray(['name' => 'Aisha Khan', 'tier' => 'Gold', 'rowVersion' => 2]);

    // An account sale pushed by Leeds: the portal's figure is the ledger sum, sent in the pull (the till ignores it).
    $charge = [...TillFixtures::sample('entities/CustomerTransaction.json'), 'customerId' => $aisha->id, 'amount' => 12.50, 'points' => 30];
    $this->sync->push([TillFixtures::envelope('CustomerTransaction', $charge, 1)])->assertOk();

    expect(collect(($this->customer)(true))->firstWhere('entityId', $aisha->id)['payload'])->toMatchArray(['balance' => 12.5, 'points' => 30]);

    $this->actingAs($this->memberOf($this->company, CompanyRole::Staff))->get("/app/customers/{$aisha->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('account.balance', '12.50')->where('account.points', 30)
            ->where('ledger.data.0.shop', 'Leeds Kirkgate')->where('canEdit', false)->where('canEmail', false));
});

test('till 0.1.51: credit held shows as such, earnsPoints is always sent (missing = true), owed / creditHeld are ours, pay dates show', function () {
    $customer = TillFixtures::sample('entities/Customer.json');
    unset($customer['earnsPoints'], $customer['pendingPoints']);
    $ledger = TillFixtures::sample('entities/CustomerTransaction.json');
    $advance = [...$ledger, 'id' => '01K5VB0000000000000CT00490', 'type' => 'advance', 'amount' => -23.40, 'saleId' => '', 'tender' => 'Card',
        'registerId' => TillFixtures::TILL_1, 'shiftId' => '01K5VB0000000SHR0010000001'];
    $payDate = Pull::payload('AccountPayDate', '01K5VB0000000000000PD00001', [
        'branchId' => TillFixtures::LEEDS, 'customerId' => $customer['id'], 'saleId' => '', 'dueAt' => '2026-10-30T00:00:00Z', 'note' => 'Payday',
        'replacedAt' => null, 'reminderSentAt' => null, 'reminderChannel' => 'none', 'reminderAttempts' => 3, 'lastReminderAt' => '2026-10-24T08:00:00Z',
        'lastReminderError' => "WhatsApp and Email not set up\nWhatsApp: no gateway.\nEmail: no SMTP server.",
    ]);
    $replaced = [...$payDate, 'id' => '01K5VB0000000000000PD00000', 'replacedAt' => '2026-10-23T08:00:00Z'];

    $this->sync->push([
        TillFixtures::envelope('Customer', [...$customer, 'owed' => 50, 'creditHeld' => 0], 1),
        TillFixtures::envelope('CustomerTransaction', $ledger, 2),
        TillFixtures::envelope('CustomerTransaction', $advance, 3),
        TillFixtures::envelope('AccountPayDate', $payDate, 4),
        TillFixtures::envelope('AccountPayDate', $replaced, 5),
    ])->assertOk()->assertJson(['acknowledgedSeq' => 5]);

    $row = collect(($this->customer)(true))->firstWhere('entityId', $customer['id']);
    expect($row['payload'])->toMatchArray(['balance' => -15, 'owed' => 0, 'creditHeld' => 15, 'earnsPoints' => true])
        ->and($row['payload'])->not->toHaveKey('pendingPoints');

    $staff = $this->memberOf($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->get('/app/customers?balance=credit')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('counts.creditHeld', '15.00')->where('customers.data.0.balance', '-15.00'));
    $this->actingAs($staff)->get("/app/customers/{$customer['id']}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('account.balance', '-15.00')
        ->where('ledger.data.0.typeLabel', 'Paid in advance')
        ->where('ledger.data.0.tender', 'Card')
        ->where('ledger.data.1.tender', null)
        ->has('payDates', 1)
        ->where('payDates.0.wholeAccount', true)
        ->where('payDates.0.shop', 'Leeds Kirkgate')
        ->where('payDates.0.reminder.state', 'failed')
        ->where('payDates.0.reminder.error', 'WhatsApp and Email not set up')
        ->where('payDates.0.reminder.detail', "WhatsApp: no gateway.\nEmail: no SMTP server.")
        ->where('payDates.0.reminder.attempts', 3));

    // A customer the portal adds collects points.
    $sam = app(SaveCustomer::class)->handle($this->company, null, ['name' => 'Sam Patel']);
    expect(collect(($this->customer)())->firstWhere('entityId', $sam->id)['payload'])
        ->toMatchArray(['earnsPoints' => true, 'owed' => 0, 'creditHeld' => 0])->not->toHaveKey('pendingPoints');
});

test('pendingPoints is stored as a till pushes it but never sent down (ANSWERS-2026-10-06 Q1: each till has its own)', function () {
    $customer = [...TillFixtures::sample('entities/Customer.json'), 'pendingPoints' => 12];
    $this->sync->push([TillFixtures::envelope('Customer', $customer, 1)])->assertOk();

    expect(DB::table('customers')->where('id', $customer['id'])->value('pending_points'))->toEqual(12);

    // Bradford gets Leeds's customer; Leeds's own figure never goes there.
    $reply = $this->sync->pull(0, bradford: true)->assertOk();
    expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $row = collect(Pull::changes($reply))->firstWhere('entityId', $customer['id']);
    expect($row)->not->toBeNull()->and($row['payload'])->not->toHaveKey('pendingPoints')->toHaveKey('earnsPoints');
});

test('pay dates of another business are never shown', function () {
    $other = new SyncApiFixtures($this, mapTillIds: false);
    $payDate = Pull::payload('AccountPayDate', '01K5VB0000000000000PD00009', ['companyId' => $other->company->id, 'customerId' => '01K5T0Q8C4000000000000K001', 'replacedAt' => null, 'lastReminderError' => '']);
    DB::table('account_pay_dates')->insert(['id' => $payDate['id'], 'company_id' => $other->company->id, 'branch_id' => $other->leeds->id,
        'customer_id' => '01K5T0Q8C4000000000000K001', 'sale_id' => '', 'due_at' => '2026-10-30 00:00:00', 'note' => '', 'user_id' => '',
        'reminder_attempts' => 0, 'last_reminder_error' => '', 'row_version' => 1]);
    $this->sync->push([TillFixtures::envelope('Customer', TillFixtures::sample('entities/Customer.json'), 1)])->assertOk();

    $this->actingAs($this->memberOf($this->company, CompanyRole::Staff))->get('/app/customers/01K5T0Q8C4000000000000K001')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payDates', 0));
});

test('a tender the portal does not know is stored and shown as the till wrote it (ANSWERS-2026-10-06 Q6: free text)', function () {
    $customer = TillFixtures::sample('entities/Customer.json');
    $payment = [...TillFixtures::sample('entities/CustomerTransaction.json'), 'id' => '01K5VB0000000000000CT00777', 'type' => 'payment', 'amount' => -5, 'saleId' => '', 'tender' => 'Gift card'];
    $this->sync->push([TillFixtures::envelope('Customer', $customer, 1), TillFixtures::envelope('CustomerTransaction', $payment, 2)])->assertOk();

    $this->actingAs($this->memberOf($this->company, CompanyRole::Staff))->get("/app/customers/{$customer['id']}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('ledger.data.0.id', $payment['id'])->where('ledger.data.0.tender', 'Gift card'));
});
