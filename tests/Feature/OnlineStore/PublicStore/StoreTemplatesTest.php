<?php

use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * OS-5: the public store in its template. Fresh Market and Butcher's Block share the pages; the template sets the
 * fonts, colours, default brand colour and banner, and the home sections' look (React switches on `store.template`).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
    $this->mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->template = fn (string $value) => DB::table('online_stores')->where('id', $this->f->store->id)->update(['template' => $value]);
});

test('Fresh Market: its own frame, forest green, the grocery banner and its fonts', function () {
    $this->get(($this->url)('store.home'))->assertOk()
        ->assertSee('data-template="freshMarket"', false)
        ->assertSee('bricolage-grotesque', false)
        ->assertDontSee('fraunces', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/home')
            ->where('store.template', 'freshMarket')
            ->where('store.brand', '#0B4A34')
            ->where('bannerUrl', asset('images/online-store/hero-grocery.jpg'))
            ->where('sections', ['hero', 'departments', 'featured', 'howItWorks', 'shops', 'about']));
});

test('Butcher\'s Block: oxblood by default, the butcher banner, Fraunces, and the same home sections in its own look', function () {
    ($this->template)('butcher');

    $this->get(($this->url)('store.home'))->assertOk()
        ->assertSee('data-template="butcher"', false)
        ->assertSee('fraunces', false)
        ->assertSee('#F7F3EC', false)
        ->assertDontSee('bricolage-grotesque', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/home')
            ->where('store.template', 'butcher')
            ->where('store.brand', '#8F1D1D')
            ->where('bannerUrl', asset('images/online-store/hero-butcher.jpg'))
            ->where('sections', ['hero', 'departments', 'featured', 'howItWorks', 'shops', 'about'])
            ->where('featured', fn ($items) => collect($items)->firstWhere('name', 'Lamb mince')['isWeighed'] === true)
            ->where('store.shop', fn ($shop) => $shop['name'] === 'Leeds' && array_key_exists('address', $shop->all()) && array_key_exists('phone', $shop->all())));
});

test('the store\'s own brand colour and banner win over the template\'s', function () {
    Storage::fake('public');
    ($this->template)('butcher');
    $this->f->store->refresh()->forceFill(['brand_colour' => '#1f4e9c', 'banner_path' => 'online-store/x/banner/b.jpg'])->save();

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.template', 'butcher')
        ->where('store.brand', '#1F4E9C')
        ->where('bannerUrl', Storage::disk('public')->url('online-store/x/banner/b.jpg')));
});

test('every store page carries the template, so the whole store wears it: :dataset', function (string $name, Closure $params, string $component) {
    ($this->template)('butcher');

    $this->get(($this->url)($name, $params($this)))->assertOk()
        ->assertSee('data-template="butcher"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component)->where('store.template', 'butcher')->where('store.brand', '#8F1D1D'));
})->with([
    'department' => ['store.department', fn ($t) => ['department' => $t->f->department], 'store/department'],
    'search' => ['store.search', fn () => ['q' => 'mince'], 'store/search'],
    'product' => ['store.product', fn ($t) => ['product' => $t->mince], 'store/product'],
    'basket' => ['store.basket', fn () => [], 'store/basket'],
    'checkout' => ['store.checkout', fn () => [], 'store/checkout'],
]);

test('an unknown template (the removed pharmacy one, or anything else) is drawn as Fresh Market', function (string $stored) {
    ($this->template)($stored);

    expect(OnlineStore::withoutCompanyScope()->findOrFail($this->f->store->id)->template)->toBe(StoreTemplate::FreshMarket);
    $this->get(($this->url)('store.home'))->assertOk()
        ->assertSee('data-template="freshMarket"', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('store.template', 'freshMarket')
            ->where('store.brand', '#0B4A34')
            ->where('bannerUrl', asset('images/online-store/hero-grocery.jpg')));
})->with(['pharmacy', 'casino', '']);

test('the migration moves stores on the removed pharmacy template to Fresh Market and leaves the others', function () {
    $b = OnlineStoreFixtures::make('Al Noor Butchers', 'al-noor');
    ($this->template)('pharmacy');
    DB::table('online_stores')->where('id', $b->store->id)->update(['template' => 'butcher']);

    (require database_path('migrations/2026_12_07_150000_map_pharmacy_store_template.php'))->up();

    expect(DB::table('online_stores')->where('id', $this->f->store->id)->value('template'))->toBe('freshMarket')
        ->and(DB::table('online_stores')->where('id', $b->store->id)->value('template'))->toBe('butcher')
        ->and(array_column(StoreTemplate::options(), 'value'))->toBe(['freshMarket', 'butcher']);
});

test('pharmacy and prescription medicines never show in any template\'s pages; general sale ones do', function (string $template) {
    ($this->template)($template);
    $gsl = $this->f->product('Paracetamol 16 tablets');
    $p = $this->f->product('Paracetamol 32 tablets');
    $pom = $this->f->product('Paracetamol prescription');
    $this->f->medicineClass($gsl, 'generalSale');
    $this->f->medicineClass($p, 'pharmacyOnly');
    $this->f->medicineClass($pom, 'prescriptionOnly');

    $this->get(($this->url)('store.search', ['q' => 'paracetamol']))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products', fn ($items) => collect($items)->pluck('name')->all() === ['Paracetamol 16 tablets']));
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featured', fn ($items) => collect($items)->pluck('id')->intersect([$p, $pom])->isEmpty()));
    $this->get(($this->url)('store.product', ['product' => $p]))->assertNotFound();
    $this->get(($this->url)('store.product', ['product' => $pom]))->assertNotFound();
})->with(['freshMarket', 'butcher']);

test('butcher: a weighed cut is picked by weight at an estimated price and ordered in kg', function () {
    ($this->template)('butcher');

    $this->get(($this->url)('store.product', ['product' => $this->mince]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.isWeighed', true)->where('product.step', '0.25')->where('product.price', '9.80')->where('product.max', '50')
        ->where('inBasket', '0'));

    // Quick weights (500 g, then 1 kg more) and an odd weight the scale steps do not allow.
    $this->post(($this->url)('store.basket.add'), ['product' => $this->mince, 'quantity' => '0.5'])->assertSessionHasNoErrors();
    $this->post(($this->url)('store.basket.add'), ['product' => $this->mince, 'quantity' => '1'])->assertSessionHasNoErrors();
    $this->post(($this->url)('store.basket.add'), ['product' => $this->mince, 'quantity' => '0.3'])
        ->assertSessionHasErrors(['quantity' => 'Choose Lamb mince in steps of 0.25 kg.']);

    $this->get(($this->url)('store.basket'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('basket.total', '14.70')->where('basket.estimated', true)
        ->where('lines.0.quantity', '1.5')->where('lines.0.product.isWeighed', true));

    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Asha Patel',
        'customerMobile' => '07700 900123', 'customerEmail' => 'asha@example.test',
    ])->assertRedirect();

    $order = OnlineOrder::withoutCompanyScope()->sole();
    $line = DB::table('online_order_lines')->where('online_order_id', $order->id)->sole();
    expect($order->items_total)->toBe('14.70')
        ->and((string) $line->quantity)->toStartWith('1.5')
        ->and((bool) $line->is_weighed)->toBeTrue()
        ->and($line->line_total + 0)->toEqual(14.7);
});

test('butcher: the honeypot still refuses the order', function () {
    ($this->template)('butcher');
    $this->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '1']);

    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $page) => $page->where('honeypot', 'website'));
    $this->postJson(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Bot', 'customerMobile' => '07700 900123',
        'customerEmail' => 'bot@example.test', 'website' => 'https://spam.example',
    ])->assertUnprocessable()->assertJsonValidationErrors(['website' => 'We could not place the order. Refresh the page and try again.']);

    expect(DB::table('online_orders')->count())->toBe(0);
});
