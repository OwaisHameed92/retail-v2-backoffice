<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Actions\IssueWorkshopApiKey;
use App\Domain\Workshop\Models\WorkshopOrder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures as T;
use Tests\Feature\Workshop\WorkshopFixtures as W;

/*
 * Tenancy (CLAUDE.md): a connection only ever sees and changes its own business's rows, and orders go to its own shop
 * only. Business B's key cannot read A's products, customers, VAT rates or orders, nor change them; A's shops'
 * pulls never carry B's orders; an admin URL mixing B with A's shop is 404.
 */

beforeEach(function () {
    $this->travelTo('2026-11-08 09:00:00');
    Http::fake();
    $this->sync = new SyncApiFixtures($this);
    $this->keyA = W::connected($this->sync);

    $this->other = Company::factory()->create(['name' => 'Otley Cycles']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['name' => 'Otley']);
    Pull::portalCreate($this->other, 'VatRate', [...T::sample('entities/VatRate.json'), 'id' => '01K5T0Q8C4000000000000V901', 'companyId' => $this->other->id]);
    Pull::portalCreate($this->other, 'Customer', [...T::sample('entities/Customer.json'), 'id' => '01K5T0Q8C4000000000000U901', 'companyId' => $this->other->id, 'name' => 'Otley Rider', 'phone' => '07700 900123', 'cardNo' => 'OT1']);
    W::addOn($this->otherShop);
    $this->keyB = app(IssueWorkshopApiKey::class)->handle($this->otherShop);
});

test('business B\'s key sees only its own business: stores, VAT rates, products, customers', function () {
    W::call($this, 'GET', '/Stores', $this->keyB)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->otherShop->id);
    W::call($this, 'GET', '/TaxRates', $this->keyB)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', '01K5T0Q8C4000000000000V901');
    W::call($this, 'GET', '/Products', $this->keyB)->assertOk()->assertExactJson([]);
    W::call($this, 'GET', '/Product/'.F::COLA, $this->keyB)->assertNotFound();
    W::call($this, 'GET', '/Inventory?variant='.F::COLA, $this->keyB)->assertOk()->assertExactJson([]);
    W::call($this, 'GET', '/Customer/list', $this->keyB)->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', '01K5T0Q8C4000000000000U901');

    // A's key never sees B's customer; A's tenant-id with B's key is 403.
    W::call($this, 'GET', '/Customer/01K5T0Q8C4000000000000U901', $this->keyA)->assertNotFound();
    W::call($this, 'GET', '/Stores', $this->keyB, [], ['tenant-id' => $this->sync->leeds->id])->assertForbidden();
});

test('business B cannot use A\'s products on an order, see or change A\'s orders, or change A\'s customers; de-dup never crosses businesses', function () {
    $a = W::call($this, 'POST', '/SalesOrder', $this->keyA, W::order($this->sync->leeds))->assertCreated()->json('id');

    W::call($this, 'POST', '/SalesOrder', $this->keyB, W::order($this->otherShop, ['lines' => [['variantId' => F::COLA, 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => '01K5T0Q8C4000000000000V901']]]))
        ->assertStatus(400);
    W::call($this, 'GET', '/SalesOrder?id='.$a, $this->keyB)->assertNotFound();
    W::call($this, 'DELETE', '/SalesOrder?id='.$a, $this->keyB)->assertNotFound();
    W::call($this, 'PUT', '/SalesOrder/'.$a.'/status/Complete', $this->keyB)->assertNotFound();
    W::call($this, 'PUT', '/SalesOrder', $this->keyB, W::order($this->otherShop, ['id' => $a, 'lines' => [['name' => 'X', 'quantity' => 1, 'paidPrice' => 1, 'taxRate' => '01K5T0Q8C4000000000000V901']]]))->assertNotFound();

    $made = W::call($this, 'POST', '/Customer', $this->keyA, ['firstName' => 'Ali', 'phone' => '07700 900123'])->assertCreated()->json('id');
    expect($made)->not->toBe('01K5T0Q8C4000000000000U901');
    W::call($this, 'PUT', '/Customer', $this->keyB, ['id' => $made, 'phone' => '1'])->assertNotFound();
    expect(Customer::withoutCompanyScope()->find($made)->phone)->toBe('07700 900123')
        ->and(WorkshopOrder::withoutCompanyScope()->where('company_id', $this->other->id)->count())->toBe(0);
});

test('orders go to their own shop\'s pull only, never another shop of the business or another business', function () {
    W::call($this, 'POST', '/SalesOrder', $this->keyA, W::order($this->sync->leeds))->assertCreated();

    expect(W::workshopRows($this->sync->pull(0)))->toHaveCount(1)
        ->and(W::workshopRows($this->sync->pull(0, bradford: true)))->toBe([]);
});

test('admin: B\'s business with A\'s shop in the URL is 404', function () {
    $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
    $this->actingAs($admin, 'admin')->get(route('admin.tenants.branches.workshop.log', [$this->other, $this->sync->leeds]))->assertNotFound();
    $this->actingAs($admin, 'admin')->post(route('admin.tenants.branches.workshop.disconnect', [$this->other, $this->sync->leeds]))->assertNotFound();
});
