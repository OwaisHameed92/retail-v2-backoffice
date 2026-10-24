<?php

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    config(['online_store.enabled' => true]);
    $this->company = Company::factory()->create();
    $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds']);
    $this->save = fn (array $input, ?Company $company = null) => app(SaveOnlineStore::class)->handle($company ?? $this->company, ['template' => 'freshMarket', ...$input]);
    $this->error = function (Closure $call): array {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        throw new RuntimeException('No validation error.');
    };
});

test('config and country flag: off by default, UK only, £20 default price', function () {
    expect(require config_path('online_store.php'))->toMatchArray(['enabled' => false, 'host' => null, 'default_monthly_price' => '20.00'])
        ->and(OnlineStoreSwitch::enabled())->toBeTrue()
        ->and(OnlineStoreSwitch::monthlyPrice(null))->toBe('20.00')
        ->and(OnlineStoreSwitch::monthlyPrice('15.00'))->toBe('15.00')
        ->and(app(Country::class)->toFrontend()['features'])->not->toHaveKey('onlineStore');

    config(['online_store.enabled' => false]);
    expect(OnlineStoreSwitch::enabled())->toBeFalse();

    config(['online_store.enabled' => true]);
    OnlineStoreFixtures::asPakistan();
    expect(OnlineStoreSwitch::enabled())->toBeFalse()
        ->and(app(Country::class)->toFrontend()['features'])->not->toHaveKey('onlineStore');
});

test('store.manage: owner and manager; shown in the role matrix only while the store is available', function () {
    expect(CompanyRole::Owner->can(Ability::StoreManage))->toBeTrue()
        ->and(CompanyRole::Manager->can('store.manage'))->toBeTrue()
        ->and(CompanyRole::Accountant->can('store.manage'))->toBeFalse()
        ->and(CompanyRole::Staff->can('store.manage'))->toBeFalse()
        // Audit OSA-32: working orders is its own ability, staff included.
        ->and(CompanyRole::Owner->can(Ability::OrdersOnline))->toBeTrue()
        ->and(CompanyRole::Manager->can('orders.online'))->toBeTrue()
        ->and(CompanyRole::Staff->can('orders.online'))->toBeTrue()
        ->and(CompanyRole::Accountant->can('orders.online'))->toBeFalse()
        ->and(collect(RoleMatrix::rows())->pluck('key'))->toContain('orders.online')
        ->and(collect(RoleMatrix::rows())->pluck('key'))->toContain('store.manage');

    config(['online_store.enabled' => false]);
    expect(collect(RoleMatrix::rows())->pluck('key'))->not->toContain('store.manage');
});

test('with the switch on, a business sees the online store role row only while its own store is available', function () {
    $f = OnlineStoreFixtures::make('Role Row Shop', 'role-row');
    $without = Company::factory()->create();

    expect(OnlineStoreSwitch::availableFor($f->company))->toBeTrue()
        ->and(collect(RoleMatrix::rows($f->company))->pluck('key'))->toContain('store.manage')
        ->and(collect(RoleMatrix::rows($without))->pluck('key'))->not->toContain('store.manage');
});

test('the first save makes the store, off line and not taking orders, with default sections; later saves change it; audited', function () {
    $store = ($this->save)(['slug' => '  Kirkgate-Leeds ', 'headline' => 'Fresh every day', 'brand_colour' => '#1f7a4d']);

    expect($store->slug)->toBe('kirkgate-leeds')
        ->and($store->template)->toBe(StoreTemplate::FreshMarket)
        ->and($store->brand_colour)->toBe('#1F7A4D')
        ->and($store->sections)->toBe(StoreSection::defaults())
        ->and($store->is_live)->toBeFalse()
        ->and($store->taking_orders)->toBeFalse()
        ->and($store->monthly_price)->toBeNull();

    $sections = [['key' => 'departments', 'enabled' => true], ['key' => 'hero', 'enabled' => false]];
    $again = ($this->save)(['slug' => 'kirkgate-leeds', 'template' => 'butcher', 'sections' => $sections, 'monthly_price' => '1.00']);

    expect($again->id)->toBe($store->id)->and($again->template)->toBe(StoreTemplate::Butcher)->and($again->sections)->toBe($sections)
        ->and($again->monthly_price)->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['online_store.created', 'online_store.updated'])->pluck('action')->all())->toBe(['online_store.created', 'online_store.updated']);
});

test('slug rules: :dataset', function (string $slug, string $message) {
    expect(($this->error)(fn () => ($this->save)(['slug' => $slug]))['slug'][0])->toBe($message);
})->with([
    'too short' => ['ab', 'Use 3 to 40 characters for the store address.'],
    'too long' => [str_repeat('a', 41), 'Use 3 to 40 characters for the store address.'],
    'a space' => ['kirk gate', 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.'],
    'an underscore' => ['kirk_gate', 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.'],
    'a leading hyphen' => ['-kirkgate', 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.'],
    'a double hyphen' => ['kirk--gate', 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.'],
    'an accent' => ['café', 'Use lower-case letters, numbers and single hyphens, starting and ending with a letter or number.'],
    'reserved admin' => ['admin', 'That address is reserved. Choose another one.'],
    'reserved store' => ['Store', 'That address is reserved. Choose another one.'],
    'reserved api' => ['api', 'That address is reserved. Choose another one.'],
]);

test('a slug is unique across businesses; a business may keep its own', function () {
    ($this->save)(['slug' => 'kirkgate']);
    $other = Company::factory()->create();

    expect(($this->error)(fn () => ($this->save)(['slug' => 'KIRKGATE'], $other)))->toBe(['slug' => ['That address is already taken. Choose another one.']])
        ->and(($this->save)(['slug' => 'kirkgate'])->slug)->toBe('kirkgate');
});

test('settings are checked', function (array $input, string $field) {
    expect(($this->error)(fn () => ($this->save)(['slug' => 'kirkgate', ...$input])))->toHaveKey($field);
})->with([
    'template' => [['template' => 'dark'], 'template'],
    'colour' => [['brand_colour' => 'green'], 'brand_colour'],
    'logo path escaping' => [['logo_path' => '../../.env'], 'logo_path'],
    'absolute banner path' => [['banner_path' => '/etc/passwd'], 'banner_path'],
    'unknown section' => [['sections' => [['key' => 'casino', 'enabled' => true]]], 'sections.0.key'],
    'repeated section' => [['sections' => [['key' => 'hero', 'enabled' => true], ['key' => 'hero', 'enabled' => false]]], 'sections.1.key'],
    'email' => [['order_email' => 'nope'], 'order_email'],
]);

test('collection shops: hours per weekday, checked; only the business\'s open shops; upserted', function () {
    ($this->save)(['slug' => 'kirkgate']);
    $shop = app(SaveStoreShop::class)->handle($this->company, $this->leeds->id, OnlineStoreFixtures::shopInput(['opening_hours' => ['monday' => ['open' => '09:00', 'close' => '17:30']]]));

    expect($shop->hoursOn('monday'))->toBe(['open' => '09:00', 'close' => '17:30'])
        ->and($shop->hoursOn('tuesday'))->toBeNull()
        ->and($shop->orders_per_slot)->toBe(2);

    $again = app(SaveStoreShop::class)->handle($this->company, $this->leeds->id, OnlineStoreFixtures::shopInput(['orders_per_slot' => 6]));
    expect($again->id)->toBe($shop->id)->and($again->orders_per_slot)->toBe(6);

    $closed = Branch::factory()->forCompany($this->company)->create(['code' => 'OLD', 'is_active' => false]);
    $theirs = Branch::factory()->forCompany(Company::factory()->create())->create(['code' => 'THR']);
    $error = fn (string $branchId, array $input = []) => ($this->error)(fn () => app(SaveStoreShop::class)->handle($this->company, $branchId, OnlineStoreFixtures::shopInput($input)));

    expect($error($closed->id))->toHaveKey('branch_id')
        ->and($error($theirs->id))->toHaveKey('branch_id')
        ->and($error($this->leeds->id, ['opening_hours' => ['monday' => ['open' => '18:00', 'close' => '09:00']]]))->toHaveKey('opening_hours.monday.close')
        ->and($error($this->leeds->id, ['opening_hours' => ['monday' => ['open' => '25:00', 'close' => '26:00']]]))->toHaveKey('opening_hours.monday.open')
        ->and($error($this->leeds->id, ['slot_minutes' => 7]))->toHaveKey('slot_minutes')
        ->and($error($this->leeds->id, ['orders_per_slot' => 0]))->toHaveKey('orders_per_slot');
});

test('a shop needs a store first', function () {
    expect(($this->error)(fn () => app(SaveStoreShop::class)->handle($this->company, $this->leeds->id, OnlineStoreFixtures::shopInput())))->toHaveKey('store');
});

test('publishing needs the switch and a collection shop; taking it off line always works; audited', function () {
    ($this->save)(['slug' => 'kirkgate']);
    OnlineStoreFixtures::addOn($this->company, $this->leeds); // OS-6: the add-on and a till with cloud sync
    expect(($this->error)(fn () => app(SetStoreLive::class)->handle($this->company, true))['store'][0])->toContain('at least one shop');

    app(SaveStoreShop::class)->handle($this->company, $this->leeds->id, OnlineStoreFixtures::shopInput());
    config(['online_store.enabled' => false]);
    expect(($this->error)(fn () => app(SetStoreLive::class)->handle($this->company, true)))->toBe(['store' => ['The online store is not available yet.']]);

    config(['online_store.enabled' => true]);
    $store = app(SetStoreLive::class)->handle($this->company, true);
    expect($store->is_live)->toBeTrue()->and($store->live_at)->not->toBeNull();

    config(['online_store.enabled' => false]);
    expect(app(SetStoreLive::class)->handle($this->company, false)->is_live)->toBeFalse()
        ->and(AuditLog::query()->whereIn('action', ['online_store.published', 'online_store.unpublished'])->count())->toBe(2);
});

test('taking orders on and off; no change writes nothing', function () {
    ($this->save)(['slug' => 'kirkgate']);

    expect(app(SetTakingOrders::class)->handle($this->company, true)->taking_orders)->toBeTrue()
        ->and(app(SetTakingOrders::class)->handle($this->company, true)->taking_orders)->toBeTrue()
        ->and(app(SetTakingOrders::class)->handle($this->company, false)->taking_orders)->toBeFalse()
        ->and(AuditLog::query()->whereIn('action', ['online_store.orders_opened', 'online_store.orders_paused'])->count())->toBe(2);
});

test('per product: hidden, description, photo; only the given keys change; the till row is untouched', function () {
    $f = OnlineStoreFixtures::make();
    $id = $f->product('Beans', ['description' => 'Till text']);
    $before = (array) DB::table('products')->where('id', $id)->first();

    app(SetOnlineProduct::class)->handle($f->company, $id, ['description' => ' Rich tomato sauce ', 'photo_path' => 'online/beans.jpg']);
    $row = app(SetOnlineProduct::class)->handle($f->company, $id, ['hidden' => true]);

    expect($row->only(['hidden', 'description', 'photo_path']))->toBe(['hidden' => true, 'description' => 'Rich tomato sauce', 'photo_path' => 'online/beans.jpg'])
        ->and((array) DB::table('products')->where('id', $id)->first())->toBe($before)
        ->and(AuditLog::query()->where('action', 'online_store.product_updated')->count())->toBe(2);

    app(SetOnlineProduct::class)->handle($f->company, $id, ['hidden' => true]);
    expect(AuditLog::query()->where('action', 'online_store.product_updated')->count())->toBe(2)
        ->and(($this->error)(fn () => app(SetOnlineProduct::class)->handle($f->company, $id, ['photo_path' => '../secret'])))->toHaveKey('photo_path')
        ->and(($this->error)(fn () => app(SetOnlineProduct::class)->handle($f->company, $id, ['description' => str_repeat('x', 1001)])))->toHaveKey('description')
        ->and(($this->error)(fn () => app(SetOnlineProduct::class)->handle($f->company, '01K5T0Q8C4000000000000P999', ['hidden' => true])))->toHaveKey('product_id')
        ->and(OnlineStoreProduct::withoutCompanyScope()->count())->toBe(1);
});

test('the template is optional: left out it stays as it is (Fresh Market for a new store); the removed pharmacy is refused', function () {
    $store = app(SaveOnlineStore::class)->handle($this->company, ['slug' => 'kirkgate']);
    expect($store->template)->toBe(StoreTemplate::FreshMarket);

    ($this->save)(['slug' => 'kirkgate', 'template' => 'butcher']);
    $again = app(SaveOnlineStore::class)->handle($this->company, ['slug' => 'kirkgate', 'headline' => 'Cut to order']);

    expect($again->template)->toBe(StoreTemplate::Butcher)->and($again->headline)->toBe('Cut to order')
        ->and(($this->error)(fn () => ($this->save)(['slug' => 'kirkgate', 'template' => 'pharmacy']))['template'][0])->toBe('The selected template is invalid.');
});
