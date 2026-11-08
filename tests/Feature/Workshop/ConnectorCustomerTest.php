<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * Connector customers (WORKSHOP-BIKEBOOK.md §3.5): list (delta, anonymised once with empty fields), get, create with
 * de-duplication by UK phone or e-mail (joint test 4), update, address; the made customer reaches every till's pull.
 * Never balance, points, credit limit, card, date of birth or notes.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::preventStrayRequests();
    $this->sync = new SyncApiFixtures($this);
    $this->key = W::connected($this->sync);
    $this->since = (int) $this->sync->pull(0)->json('highestVersion');
});

test('create: 201 and in the next pull of every till; the same phone written another way, or the same e-mail, returns the same customer (joint test 4)', function () {
    $made = W::call($this, 'POST', '/Customer', $this->key, ['firstName' => 'Ali', 'lastName' => 'Khan', 'phone' => '07700 900123', 'emailAddress' => 'Ali@Example.co.uk',
        'addresses' => [['line1' => '2 Kirkgate', 'town' => 'Leeds', 'postcode' => 'LS1 6AB']]])->assertCreated();

    expect($made->json())->toMatchArray(['firstName' => 'Ali', 'lastName' => 'Khan', 'emailAddress' => 'ali@example.co.uk', 'phone' => '07700 900123',
        'addresses' => [['line1' => '2 Kirkgate', 'line2' => '', 'town' => 'Leeds', 'postcode' => 'LS1 6AB']]])
        ->and(array_keys($made->json()))->not->toContain('balance', 'points', 'creditLimit', 'cardNo', 'dob', 'notes');

    W::call($this, 'POST', '/Customer', $this->key, ['firstName' => 'A', 'lastName' => 'Khan', 'phone' => '+44 7700-900123'])->assertOk()->assertJsonPath('id', $made->json('id'));
    W::call($this, 'POST', '/Customer', $this->key, ['firstName' => 'Ali', 'emailAddress' => 'ALI@example.co.uk'])->assertOk()->assertJsonPath('id', $made->json('id'));
    expect(Customer::withoutCompanyScope()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'customer.created')->count())->toBe(1);

    foreach ([false, true] as $bradford) {
        $rows = collect($this->sync->pull($this->since, bradford: $bradford)->json('changes'))->where('entity', 'Customer');
        expect($rows->pluck('entityId')->all())->toBe([$made->json('id')]);
    }
});

test('create: at least a name, phone or e-mail; a bad e-mail is 400', function () {
    W::call($this, 'POST', '/Customer', $this->key, [])->assertStatus(400)->assertJsonPath('message', 'Send at least a name, phone or emailAddress.');
    W::call($this, 'POST', '/Customer', $this->key, ['firstName' => 'Ali', 'emailAddress' => 'not-an-email'])->assertStatus(400);
});

test('get, update and address; never the private members; an anonymised customer is shown empty and cannot be changed', function () {
    Pull::portalCreate($this->sync->company, 'Customer', [...T::sample('entities/Customer.json'), 'id' => '01K5T0Q8C4000000000000U001',
        'name' => 'Mary Jane Watson', 'phone' => '07700 900999', 'email' => 'mj@example.co.uk', 'balance' => 12.5, 'points' => 40, 'creditLimit' => 100,
        'cardNo' => 'LC1', 'notes' => 'VIP', 'address' => '1 Park Row, Headingley, Leeds LS6 3AA']);

    $customer = W::call($this, 'GET', '/Customer/01K5T0Q8C4000000000000U001', $this->key)->assertOk()->json();
    expect($customer)->toMatchArray(['firstName' => 'Mary Jane', 'lastName' => 'Watson', 'addresses' => [['line1' => '1 Park Row', 'line2' => 'Headingley', 'town' => 'Leeds', 'postcode' => 'LS6 3AA']]])
        ->and(array_keys($customer))->toBe(['id', 'firstName', 'lastName', 'emailAddress', 'phone', 'createDate', 'updateDate', 'version', 'addresses', 'anonymised']);

    W::call($this, 'PUT', '/Customer', $this->key, ['id' => '01K5T0Q8C4000000000000U001', 'phone' => '07700 900111'])->assertOk()->assertJsonPath('phone', '07700 900111')->assertJsonPath('firstName', 'Mary Jane');
    W::call($this, 'PUT', '/Customer/01K5T0Q8C4000000000000U001/Address', $this->key, ['line1' => '9 Briggate', 'town' => 'Leeds', 'postcode' => 'LS1 6HD'])
        ->assertOk()->assertJsonPath('addresses.0.line1', '9 Briggate');
    $stored = Customer::withoutCompanyScope()->find('01K5T0Q8C4000000000000U001');
    expect($stored->address)->toBe('9 Briggate, Leeds, LS1 6HD')->and($stored->balance)->toBe('12.50')->and($stored->notes)->toBe('VIP');

    W::call($this, 'PUT', '/Customer', $this->key, ['id' => '01K5T0Q8C4000000000000U999', 'phone' => '1'])->assertNotFound();
    W::call($this, 'PUT', '/Customer', $this->key, ['phone' => '1'])->assertStatus(400);

    Customer::withoutCompanyScope()->whereKey('01K5T0Q8C4000000000000U001')->update(['anonymised_at' => '2026-11-08 10:00:00', 'updated_at' => '2026-11-08 10:00:00']);
    W::call($this, 'GET', '/Customer/01K5T0Q8C4000000000000U001', $this->key)->assertOk()->assertJsonPath('firstName', '')->assertJsonPath('anonymised', true)->assertJsonPath('addresses', []);
    W::call($this, 'PUT', '/Customer', $this->key, ['id' => '01K5T0Q8C4000000000000U001', 'phone' => '1'])->assertStatus(400);
});

test('list: active customers, paged; with lastUpdated the changed ones and an anonymised one once; fields=id,updated is light', function () {
    foreach (['U001' => 'Ann Lee', 'U002' => 'Bob Ray'] as $id => $name) {
        Pull::portalCreate($this->sync->company, 'Customer', [...T::sample('entities/Customer.json'), 'id' => "01K5T0Q8C4000000000000{$id}", 'name' => $name, 'cardNo' => $id]);
    }

    W::call($this, 'GET', '/Customer/list?pageSize=1', $this->key)->assertOk()->assertHeader('X-Total-Count', '2')->assertJsonCount(1);
    W::call($this, 'GET', '/Customer/list?fields=id,updated', $this->key)->assertOk()->assertJsonPath('0', ['id' => '01K5T0Q8C4000000000000U001', 'updated' => '2026-11-08T09:00:00Z']);

    $this->travel(1)->hours();
    $since = now('UTC')->format('Y-m-d\TH:i:s\Z');
    Customer::withoutCompanyScope()->whereKey('01K5T0Q8C4000000000000U002')->update(['anonymised_at' => now('UTC'), 'updated_at' => now('UTC')]);
    W::call($this, 'GET', '/Customer/list?lastUpdated='.$since, $this->key)->assertOk()->assertJsonCount(1)
        ->assertJsonPath('0.id', '01K5T0Q8C4000000000000U002')->assertJsonPath('0.anonymised', true);
    W::call($this, 'GET', '/Customer/list', $this->key)->assertOk()->assertJsonCount(1);
});
