<?php

use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/**
 * Till 0.1.15 (PORTAL-CHANGES item 3): each shop's till makes its own "Order deposit" and "Loyalty points" payment
 * types, so the business holds one row per shop. The list groups them by name; a change is made to every shop's row.
 */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->cash = app(SavePaymentType::class)->handle($this->company, null, ['name' => 'Cash', 'is_cash' => true, 'opens_drawer' => true]);

    $this->tillType = fn (Company $company, Branch $shop, string $name, int $position, array $flags = []) => app(CurrentCompany::class)->runAs($company, fn () => PaymentType::query()->forceCreate([
        'id' => (string) Str::ulid(), 'name' => $name, 'position' => $position, 'origin_branch_id' => $shop->id, 'is_cash' => false, 'is_card' => false,
        'is_voucher' => false, 'is_points' => $name === 'Loyalty points', 'is_account' => false, 'is_drs_refund' => false, 'opens_drawer' => false,
        'show_on_payment' => true, 'show_on_refund' => false, 'show_on_customer_payment' => false, 'is_active' => true, ...$flags,
    ]));

    $this->leedsDeposit = ($this->tillType)($this->company, $this->sync->leeds, 'Order deposit', 6);
    $this->bradfordDeposit = ($this->tillType)($this->company, $this->sync->bradford, 'Order deposit', 8);
    ($this->tillType)($this->company, $this->sync->leeds, 'Loyalty points', 7);
    ($this->tillType)($this->company, $this->sync->bradford, 'Loyalty points', 7);
    $this->form = fn (PaymentType $t, array $changes = []) => [...$t->only(['name', 'position', ...SavePaymentType::FLAGS]), ...$changes];
});

test('the list shows one line per name, saying how many shops have it and which ones are the till\'s own', function () {
    $this->actingAs($this->manager)->get('/app/payment-types?sort=name')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/till-lists/payment-types')
        ->where('counts', ['all' => 3, 'active' => 3])
        ->has('paymentTypes.data', 3)
        ->where('paymentTypes.meta.total', 3)
        ->where('paymentTypes.data.0.name', 'Cash')->where('paymentTypes.data.0.shops', 1)->where('paymentTypes.data.0.system', false)
        ->where('paymentTypes.data.1.name', 'Loyalty points')->where('paymentTypes.data.1.shops', 2)->where('paymentTypes.data.1.mixed', false)
        ->where('paymentTypes.data.2.name', 'Order deposit')->where('paymentTypes.data.2.shops', 2)->where('paymentTypes.data.2.system', true)
        ->where('paymentTypes.data.2.mixed', true)); // Leeds has it 6th, Bradford 8th
});

test('saving a per-shop type passes the name check and changes each shop\'s row, leaving what was not changed', function () {
    $this->actingAs($this->manager)->put("/app/payment-types/{$this->bradfordDeposit->id}", ($this->form)($this->bradfordDeposit, ['show_on_refund' => true]))
        ->assertSessionHasNoErrors()->assertRedirect();

    $rows = PaymentType::withoutCompanyScope()->where('name', 'Order deposit')->orderBy('position')->get();
    expect($rows->pluck('show_on_refund')->all())->toBe([true, true])
        ->and($rows->pluck('position')->all())->toBe([6, 8]); // each shop keeps its own order

    $this->actingAs($this->manager)->put("/app/payment-types/{$this->leedsDeposit->id}", ($this->form)($this->leedsDeposit->fresh(), ['position' => 9]))->assertSessionHasNoErrors();
    expect(PaymentType::withoutCompanyScope()->where('name', 'Order deposit')->pluck('position')->all())->toBe([9, 9]);
});

test('the till\'s own types keep their name and cannot be removed; other same-named types are renamed and removed together', function () {
    $this->actingAs($this->manager)->put("/app/payment-types/{$this->leedsDeposit->id}", ($this->form)($this->leedsDeposit, ['name' => 'Deposit']))->assertSessionHasErrors('name');
    $this->actingAs($this->manager)->delete("/app/payment-types/{$this->leedsDeposit->id}")->assertSessionHasErrors('status');
    $this->actingAs($this->manager)->post('/app/payment-types', ['name' => 'order deposit'])->assertSessionHasErrors('name');
    expect(PaymentType::withoutCompanyScope()->where('name', 'Order deposit')->count())->toBe(2);

    $leedsCard = ($this->tillType)($this->company, $this->sync->leeds, 'Card', 2, ['is_card' => true]);
    ($this->tillType)($this->company, $this->sync->bradford, 'CARD', 2, ['is_card' => true]);

    $this->actingAs($this->manager)->put("/app/payment-types/{$leedsCard->id}", ($this->form)($leedsCard, ['name' => 'Cash']))->assertSessionHasErrors('name');
    $this->actingAs($this->manager)->put("/app/payment-types/{$leedsCard->id}", ($this->form)($leedsCard, ['name' => 'Debit card']))->assertSessionHasNoErrors();
    expect(PaymentType::withoutCompanyScope()->where('name', 'Debit card')->count())->toBe(2);

    $this->actingAs($this->manager)->delete("/app/payment-types/{$leedsCard->id}")->assertSessionHasNoErrors();
    expect(PaymentType::withoutCompanyScope()->where('name', 'Debit card')->count())->toBe(0)
        ->and(PaymentType::withoutCompanyScope()->onlyTrashed()->where('name', 'Debit card')->count())->toBe(2);
});

test('the last cash type counts the whole group: switching off a cash type made in every shop is refused', function () {
    $this->cash->delete();
    $leeds = ($this->tillType)($this->company, $this->sync->leeds, 'Cash', 1, ['is_cash' => true]);
    ($this->tillType)($this->company, $this->sync->bradford, 'Cash', 1, ['is_cash' => true]);

    expect(fn () => app(SavePaymentType::class)->handle($this->company, $leeds->id, ($this->form)($leeds, ['is_active' => false])))
        ->toThrow(ValidationException::class, 'only active cash payment type');
});

test('another business\'s same-named types are never grouped with ours or changed', function () {
    $other = Company::factory()->create();
    $theirs = ($this->tillType)($other, Branch::factory()->forCompany($other)->create(['code' => 'OTH']), 'Order deposit', 6);

    $this->actingAs($this->manager)->put("/app/payment-types/{$this->leedsDeposit->id}", ($this->form)($this->leedsDeposit, ['is_active' => false]))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put("/app/payment-types/{$theirs->id}", ($this->form)($theirs))->assertNotFound();

    expect(PaymentType::withoutCompanyScope()->findOrFail($theirs->id)->is_active)->toBeTrue()
        ->and(PaymentType::withoutCompanyScope()->where('company_id', $this->company->id)->where('name', 'Order deposit')->pluck('is_active')->all())->toBe([false, false]);
});
