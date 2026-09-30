<?php

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Tenancy\Enums\CompanyRole;
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
