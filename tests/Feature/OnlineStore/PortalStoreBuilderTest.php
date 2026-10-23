<?php

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-3: the portal's online store pages (store builder, collection shops, products): access, the switch, the store
 * builder's save with its logo and banner, go live / take offline and "Taking orders".
 */

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('public');
    $this->f = OnlineStoreFixtures::make();
    $this->company = $this->f->company;
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->manager = F::member($this->company, CompanyRole::Manager);
    $this->settings = fn (array $overrides = []) => [
        'slug' => 'kirkgate', 'template' => 'butcher', 'headline' => 'Cut to order', 'brand_colour' => '#b42318', 'order_email' => 'orders@example.test',
        'sections' => [['key' => 'departments', 'enabled' => true], ['key' => 'hero', 'enabled' => false]], ...$overrides,
    ];
    $this->store = fn (?Company $company = null) => OnlineStore::withoutCompanyScope()->where('company_id', ($company ?? $this->company)->id)->sole();
});

function osPortalUrls(Company $company, Branch $branch, string $productId): array
{
    return [
        ['get', '/app/online-store', []],
        ['get', '/app/online-store/shops', []],
        ['get', '/app/online-store/products', []],
        ['post', '/app/online-store', ['slug' => 'kirkgate', 'template' => 'freshMarket', 'sections' => [['key' => 'hero', 'enabled' => true]]]],
        ['put', '/app/online-store/live', ['on' => false]],
        ['put', '/app/online-store/taking-orders', ['on' => false]],
        ['put', "/app/online-store/shops/{$branch->id}", OnlineStoreFixtures::shopInput()],
        ['post', "/app/online-store/products/{$productId}", ['hidden' => true]],
        ['post', '/app/online-store/products/visibility', ['ids' => [$productId], 'hidden' => true]],
    ];
}

test('owners and managers open every page; staff and accountants get 403; guests go to the login page', function () {
    $product = $this->f->product('Milk');
    $urls = osPortalUrls($this->company, $this->f->leeds, $product);

    foreach ($urls as [$method, $url, $body]) {
        $this->{$method}($url, $body)->assertRedirect('/login');
    }

    foreach ([CompanyRole::Staff, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        foreach ($urls as [$method, $url, $body]) {
            $this->actingAs($user)->{$method.'Json'}($url, $body)->assertForbidden();
        }
    }

    foreach ([$this->owner, $this->manager] as $user) {
        $this->actingAs($user)->get('/app/online-store')->assertOk()->assertInertia(fn (Assert $p) => $p->component('app/online-store/builder')
            ->where('abilities', fn ($a) => collect($a)->contains('store.manage')));
        $this->actingAs($user)->get('/app/online-store/shops')->assertOk()->assertInertia(fn (Assert $p) => $p->component('app/online-store/shops'));
        $this->actingAs($user)->get('/app/online-store/products')->assertOk()->assertInertia(fn (Assert $p) => $p->component('app/online-store/products'));
    }
});

test('with the online store switched off (or in Pakistan) every page answers 404 and the menu ability is not shared', function () {
    $product = $this->f->product('Milk');

    foreach ([fn () => config(['online_store.enabled' => false]), fn () => OnlineStoreFixtures::asPakistan()] as $off) {
        config(['online_store.enabled' => true]);
        $off();
        foreach (osPortalUrls($this->company, $this->f->leeds, $product) as [$method, $url, $body]) {
            $this->actingAs($this->manager)->{$method.'Json'}($url, $body)->assertNotFound();
        }
        $this->actingAs($this->manager)->get('/app')->assertOk()->assertInertia(fn (Assert $p) => $p->where('abilities', fn ($a) => ! collect($a)->contains('store.manage')));
    }

    expect(($this->store)()->is_live)->toBeTrue();
});

test('the builder page shows the saved store, its full address, the templates with their photos and the checklist', function () {
    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p
        ->where('store.slug', 'kirkgate')
        ->where('store.template', 'freshMarket')
        ->where('store.isLive', true)
        ->where('store.takingOrders', true)
        ->where('store.address', url('/store/kirkgate'))
        ->where('addressPrefix', url('/store').'/')
        ->where('templates.1.value', 'butcher')
        ->where('templates.1.photo', asset('images/online-store/hero-butcher.jpg'))
        ->where('checklist.collectionShops', 1)
        ->where('checklist.productsShop', 'Leeds')
        ->has('sectionOptions', 6)
        ->has('store.sections', 6)
        ->where('canEdit', true));
});

test('a business without the add-on (so without a store) gets 404; the admin\'s switch makes the store (OS-6)', function () {
    $other = Company::factory()->create(['name' => 'Al Noor Butchers', 'email' => 'hello@alnoor.test']);
    Branch::factory()->forCompany($other)->create();
    $owner = F::member($other, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/online-store')->assertNotFound();
    $this->actingAs($owner)->post('/app/online-store', ($this->settings)(['slug' => 'al-noor']))->assertNotFound();
    expect(OnlineStore::withoutCompanyScope()->where('company_id', $other->id)->exists())->toBeFalse();
});

test('saving changes the store and uploads the logo and banner to the business\'s own folder with a new name', function () {
    $this->actingAs($this->owner)->post('/app/online-store', ($this->settings)([
        'logo' => UploadedFile::fake()->image('My Logo.PNG', 300, 300),
        'banner' => UploadedFile::fake()->image('../../banner.jpg', 1600, 600),
        'logo_path' => 'online-store/someone-else/logo/x.png',
    ]))->assertRedirect('/app/online-store')->assertSessionHas('success', 'Changes saved. Customers see them now.');

    $store = ($this->store)();
    expect($store->template->value)->toBe('butcher')
        ->and($store->headline)->toBe('Cut to order')
        ->and($store->brand_colour)->toBe('#B42318')
        ->and($store->sections)->toBe([['key' => 'departments', 'enabled' => true], ['key' => 'hero', 'enabled' => false]])
        ->and($store->logo_path)->toMatch('#^online-store/'.$this->company->id.'/logo/[0-9a-z]{26}\.png$#')
        ->and($store->banner_path)->toMatch('#^online-store/'.$this->company->id.'/banner/[0-9a-z]{26}\.jpg$#');
    Storage::disk('public')->assertExists([$store->logo_path, $store->banner_path]);
    expect(AuditLog::query()->where('action', 'online_store.updated')->count())->toBe(1);

    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p
        ->where('store.logoUrl', Storage::disk('public')->url($store->logo_path))
        ->where('store.sections', [['key' => 'departments', 'enabled' => true], ['key' => 'hero', 'enabled' => false],
            ['key' => 'featured', 'enabled' => false], ['key' => 'howItWorks', 'enabled' => false], ['key' => 'shops', 'enabled' => false], ['key' => 'about', 'enabled' => false]]));
});

test('a replaced logo is deleted, a removed banner is deleted, and saving without files keeps them', function () {
    $this->actingAs($this->owner)->post('/app/online-store', ($this->settings)(['logo' => UploadedFile::fake()->image('a.png', 100, 100), 'banner' => UploadedFile::fake()->image('b.jpg', 800, 300)]));
    $first = ($this->store)();

    $this->actingAs($this->owner)->post('/app/online-store', ($this->settings)(['headline' => 'Again']))->assertSessionHasNoErrors();
    expect(($this->store)()->logo_path)->toBe($first->logo_path)->and(($this->store)()->banner_path)->toBe($first->banner_path);

    $this->actingAs($this->owner)->post('/app/online-store', ($this->settings)(['logo' => UploadedFile::fake()->image('c.webp', 100, 100), 'remove_banner' => true]))->assertSessionHasNoErrors();
    $store = ($this->store)();

    expect($store->logo_path)->not->toBe($first->logo_path)->toEndWith('.webp')->and($store->banner_path)->toBeNull();
    Storage::disk('public')->assertMissing([$first->logo_path, $first->banner_path]);
    Storage::disk('public')->assertExists($store->logo_path);
});

test('uploads must be JPG, PNG or WebP pictures of 4 MB or less: :dataset', function (Closure $file, string $message) {
    $this->actingAs($this->owner)->postJson('/app/online-store', ($this->settings)(['logo' => $file()]))
        ->assertUnprocessable()->assertJsonPath('errors.logo.0', $message);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(($this->store)()->template->value)->toBe('freshMarket');
})->with([
    'a PDF' => [fn () => UploadedFile::fake()->create('logo.pdf', 20, 'application/pdf'), 'Choose a JPG, PNG or WebP picture.'],
    'a GIF' => [fn () => UploadedFile::fake()->image('logo.gif', 50, 50), 'Choose a JPG, PNG or WebP picture.'],
    'text named .png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'), 'Choose a JPG, PNG or WebP picture, 16 to 8000 pixels wide and high.'],
    'too small' => [fn () => UploadedFile::fake()->image('logo.png', 8, 8), 'Choose a JPG, PNG or WebP picture, 16 to 8000 pixels wide and high.'],
    'over 4 MB' => [fn () => UploadedFile::fake()->image('logo.jpg', 200, 200)->size(4097), 'Choose a picture of 4 MB or less.'],
]);

test('settings are checked (422) and a refused save leaves no uploaded file behind: :dataset', function (array $input, string $field) {
    $this->actingAs($this->owner)->postJson('/app/online-store', ($this->settings)([...$input, 'logo' => UploadedFile::fake()->image('a.png', 64, 64)]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(Storage::disk('public')->allFiles())->toBe([])->and(($this->store)()->slug)->toBe('kirkgate');
})->with([
    'reserved address' => [['slug' => 'admin'], 'slug'],
    'bad address' => [['slug' => 'kirk gate!'], 'slug'],
    'no address' => [['slug' => ''], 'slug'],
    'unknown template' => [['template' => 'casino'], 'template'],
    'colour' => [['brand_colour' => 'red'], 'brand_colour'],
    'email' => [['order_email' => 'not-an-email'], 'order_email'],
    'unknown section' => [['sections' => [['key' => 'casino', 'enabled' => true]]], 'sections.0.key'],
    'headline too long' => [['headline' => str_repeat('a', 161)], 'headline'],
]);

test('another business\'s address is refused', function () {
    $b = OnlineStoreFixtures::make('Other Shop', 'other-shop');

    $this->actingAs($this->owner)->postJson('/app/online-store', ($this->settings)(['slug' => 'other-shop']))
        ->assertUnprocessable()->assertJsonPath('errors.slug.0', 'That address is already taken. Choose another one.');
    expect(($this->store)($b->company)->slug)->toBe('other-shop');
});

test('a one-shop manager may look but not change the store', function () {
    $oneShop = F::member($this->company, CompanyRole::Manager, $this->f->leeds);

    $this->actingAs($oneShop)->get('/app/online-store')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
    $this->actingAs($oneShop)->postJson('/app/online-store', ($this->settings)())->assertForbidden();
    $this->actingAs($oneShop)->putJson('/app/online-store/live', ['on' => false])->assertForbidden();
    $this->actingAs($oneShop)->putJson('/app/online-store/taking-orders', ['on' => false])->assertForbidden();
    expect(($this->store)()->is_live)->toBeTrue();
});

test('go live needs a collection shop; take offline and the taking orders switch work; audited', function () {
    $audits = fn () => AuditLog::query()->where('company_id', $this->company->id)->whereIn('action', ['online_store.unpublished', 'online_store.published', 'online_store.orders_paused'])->count();
    $before = $audits();
    $this->actingAs($this->owner)->put('/app/online-store/live', ['on' => false])->assertSessionHas('success', 'Your store is offline. Its address no longer opens.');
    expect(($this->store)()->is_live)->toBeFalse();

    $this->f->shop->forceFill(['enabled' => false])->save();
    $this->actingAs($this->owner)->putJson('/app/online-store/live', ['on' => true])->assertUnprocessable()
        ->assertJsonPath('errors.store.0', 'Add at least one shop customers can collect from before you publish the store.');

    $this->f->shop->forceFill(['enabled' => true])->save();
    $this->actingAs($this->owner)->put('/app/online-store/live', ['on' => true])->assertSessionHas('success');
    $this->actingAs($this->owner)->put('/app/online-store/taking-orders', ['on' => false])->assertSessionHas('success', 'Orders paused. Customers can still look around the store.');
    $this->actingAs($this->owner)->putJson('/app/online-store/taking-orders', [])->assertUnprocessable()->assertJsonValidationErrors('on');

    expect(($this->store)()->is_live)->toBeTrue()->and(($this->store)()->taking_orders)->toBeFalse()
        ->and($audits() - $before)->toBe(3);
});

test('company A can never read or change company B\'s store', function () {
    $b = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    app(SaveOnlineStore::class)->handle($b->company, ['slug' => 'other-shop', 'template' => 'pharmacy', 'headline' => 'B only']);
    $bLogo = StoreImages::put($b->company, UploadedFile::fake()->image('b.png', 50, 50), StoreImages::LOGO);
    OnlineStore::withoutCompanyScope()->where('company_id', $b->company->id)->update(['logo_path' => $bLogo]);

    $this->actingAs($this->owner)->get('/app/online-store')->assertInertia(fn (Assert $p) => $p->where('store.slug', 'kirkgate')->where('store.logoUrl', null));
    $this->actingAs($this->owner)->post('/app/online-store', ($this->settings)(['remove_logo' => true, 'logo_path' => $bLogo]))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put('/app/online-store/live', ['on' => false]);
    $this->actingAs($this->owner)->put('/app/online-store/taking-orders', ['on' => false]);

    $bStore = ($this->store)($b->company);
    expect($bStore->headline)->toBe('B only')->and($bStore->is_live)->toBeTrue()->and($bStore->taking_orders)->toBeTrue()->and($bStore->logo_path)->toBe($bLogo)
        ->and(($this->store)()->logo_path)->toBeNull();
    Storage::disk('public')->assertExists($bLogo);
    expect(StoreImages::owns($this->company, $bLogo))->toBeFalse();
});
