<?php

use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Setup\Actions\SaveReason;
use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\Supplier;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Module 4.5: suppliers (`suppliers.manage`), payment types and reasons (`settings.manage`) on the tenant portal. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->supplier = app(SaveSupplier::class)->handle($this->company, null, ['name' => 'Booker Leeds', 'code' => 'BOOK', 'terms_kind' => 'onDelivery', 'order_method' => 'rep']);
    $this->cash = app(SavePaymentType::class)->handle($this->company, null, ['name' => 'Cash', 'is_cash' => true]);
    $this->reason = app(SaveReason::class)->handle($this->company, null, ['type' => 'void', 'text' => 'Scanned twice']);
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->form = ['name' => 'Aire Valley', 'code' => '', 'is_active' => true, 'terms_kind' => 'netDays', 'payment_terms_days' => 30,
        'order_method' => 'email', 'minimum_order_value' => '49.99', 'delivery_days' => ['monday'], 'email' => 'orders@airevalley.example'];
});

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function setupWrites(object $t): array
{
    return [
        ['post', '/app/suppliers', $t->form], ['put', "/app/suppliers/{$t->supplier->id}", $t->form], ['delete', "/app/suppliers/{$t->supplier->id}", []],
        ['post', '/app/payment-types', ['name' => 'Card', 'is_card' => true]], ['put', "/app/payment-types/{$t->cash->id}", ['name' => 'Notes']],
        ['delete', "/app/payment-types/{$t->cash->id}", []], ['post', '/app/reasons', ['type' => 'refund', 'text' => 'Faulty']],
        ['put', "/app/reasons/{$t->reason->id}", ['type' => 'void', 'text' => 'Wrong item']], ['delete', "/app/reasons/{$t->reason->id}", []],
    ];
}

test('guests are sent to the login page', function () {
    foreach (['/app/suppliers', '/app/suppliers/create', '/app/payment-types', '/app/reasons'] as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    foreach (setupWrites($this) as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertRedirect('/login');
    }
});

test('staff and accountants get 403 everywhere; one-shop managers may only look', function (CompanyRole $role, bool $oneShop) {
    $user = $this->memberOf($this->company, $role);
    if ($oneShop) {
        $this->company->users()->updateExistingPivot($user->id, ['branch_id' => $this->sync->leeds->id]);
    }

    foreach (['/app/suppliers', '/app/suppliers/create', "/app/suppliers/{$this->supplier->id}/edit", '/app/payment-types', '/app/reasons'] as $url) {
        $oneShop ? $this->actingAs($user)->get($url)->assertOk() : $this->actingAs($user)->get($url)->assertForbidden();
    }
    foreach (setupWrites($this) as [$method, $url, $data]) {
        $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
    }

    expect(Supplier::withoutCompanyScope()->count())->toBe(1)->and(Supplier::withoutCompanyScope()->sole()->name)->toBe('Booker Leeds')
        ->and(PaymentType::withoutCompanyScope()->sole()->name)->toBe('Cash')
        ->and(Reason::withoutCompanyScope()->sole()->text)->toBe('Scanned twice');
})->with([[CompanyRole::Staff, false], [CompanyRole::Accountant, false], [CompanyRole::Manager, true]]);

test('managers list, add, edit and remove suppliers with an audit trail', function () {
    $this->actingAs($this->manager)->get('/app/suppliers?search=book')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/suppliers/index')->where('counts', ['all' => 1, 'active' => 1, 'inactive' => 0])
        ->where('suppliers.data.0.code', 'BOOK')->where('suppliers.data.0.terms', 'Pay on delivery')->where('suppliers.data.0.orderMethod', 'Rep visit'));
    $this->actingAs($this->manager)->get("/app/suppliers/{$this->supplier->id}/edit")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/suppliers/form')->where('supplier.code', 'BOOK')->has('options.termsKinds', 4));

    $this->actingAs($this->manager)->post('/app/suppliers', $this->form)->assertRedirect('/app/suppliers')->assertSessionHas('success');
    $new = Supplier::withoutCompanyScope()->where('name', 'Aire Valley')->sole();
    expect([$new->code, $new->minimum_order_value, $new->delivery_days, $new->company_id])->toBe(['AIREVA', '49.99', 'monday', $this->company->id]);

    $this->actingAs($this->manager)->post('/app/suppliers', [...$this->form, 'code' => 'book'])->assertSessionHasErrors('code');
    $this->actingAs($this->manager)->post('/app/suppliers', [...$this->form, 'payment_terms_days' => null])->assertSessionHasErrors('payment_terms_days');
    $this->actingAs($this->manager)->put("/app/suppliers/{$new->id}", [...$this->form, 'code' => 'AVCC', 'is_active' => false])->assertRedirect('/app/suppliers');
    expect($new->fresh()->code)->toBe('AVCC')->and($new->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)->delete("/app/suppliers/{$new->id}")->assertRedirect('/app/suppliers');
    expect(Supplier::withoutCompanyScope()->withTrashed()->findOrFail($new->id)->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'like', 'supplier.%')->pluck('action')->all())->toEqualCanonicalizing(['supplier.created', 'supplier.created', 'supplier.updated', 'supplier.deleted']);
});

test('managers manage payment types and reasons; the last cash type stays', function () {
    $this->actingAs($this->manager)->get('/app/payment-types')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/till-lists/payment-types')->where('paymentTypes.data.0.kind', 'Cash')->where('canEdit', true));
    $this->actingAs($this->manager)->post('/app/payment-types', ['name' => 'Card', 'is_card' => true, 'show_on_payment' => true, 'is_active' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post('/app/payment-types', ['name' => 'card'])->assertSessionHasErrors('name');
    $this->actingAs($this->manager)->put("/app/payment-types/{$this->cash->id}", ['name' => 'Cash', 'is_cash' => true, 'is_active' => false])->assertSessionHasErrors('is_active');
    $this->actingAs($this->manager)->delete("/app/payment-types/{$this->cash->id}")->assertSessionHasErrors('status');
    expect(PaymentType::withoutCompanyScope()->where('name', 'Card')->sole()->position)->toBe(2);

    $this->actingAs($this->manager)->get('/app/reasons?type=void')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/till-lists/reasons')->where('filters.type', 'void')->where('reasons.data.0.typeLabel', 'Void')->has('types', 17));
    $this->actingAs($this->manager)->post('/app/reasons', ['type' => 'refund', 'text' => 'Faulty', 'account_code' => '4010'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post('/app/reasons', ['type' => 'void', 'text' => 'scanned twice'])->assertSessionHasErrors('text');
    $this->actingAs($this->manager)->post('/app/reasons', ['type' => 'made-up', 'text' => 'X'])->assertSessionHasErrors('type');
    $this->actingAs($this->manager)->put("/app/reasons/{$this->reason->id}", ['type' => 'void', 'text' => 'Wrong item', 'is_active' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->delete("/app/reasons/{$this->reason->id}")->assertSessionHasNoErrors();

    expect(Reason::withoutCompanyScope()->where('text', 'Faulty')->sole()->account_code)->toBe('4010')
        ->and(Reason::withoutCompanyScope()->withTrashed()->findOrFail($this->reason->id)->text)->toBe('Wrong item');
});

test('another business\'s suppliers, payment types and reasons are not found and never listed', function () {
    $theirOwner = $this->memberOf(Company::factory()->create(), CompanyRole::Owner);

    $this->actingAs($theirOwner)->get('/app/suppliers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('suppliers.data', 0));
    $this->actingAs($theirOwner)->get('/app/payment-types')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('paymentTypes.data', 0));
    $this->actingAs($theirOwner)->get('/app/reasons')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('reasons.data', 0));
    $this->actingAs($theirOwner)->get("/app/suppliers/{$this->supplier->id}/edit")->assertNotFound();

    foreach (array_filter(setupWrites($this), fn ($w) => $w[0] !== 'post') as [$method, $url, $data]) {
        $this->actingAs($theirOwner)->{$method}($url, $data)->assertNotFound();
    }

    expect(Supplier::withoutCompanyScope()->findOrFail($this->supplier->id)->name)->toBe('Booker Leeds')
        ->and(PaymentType::withoutCompanyScope()->findOrFail($this->cash->id)->name)->toBe('Cash');
});
