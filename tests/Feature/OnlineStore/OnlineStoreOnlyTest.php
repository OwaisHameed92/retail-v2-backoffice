<?php

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\UndoOnlineOrderStatus;
use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Queries\FindStoreBySlug;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Support\OnlineStoreOnly;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\Product;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\OnlineStoreOnlyHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * OS-13 (owner 2026-10-26): "Online store only" for a business with no till. Made by the wizard with no tills or keys,
 * its store works without cloud sync on the portal's products and the shop's own stock, it pays the single "Online
 * store" line, and Change plan to a plan with tills hands over to the tills' stock and prices.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class, ChangePlanHelpers::class, OnlineStoreOnlyHelpers::class);

const OSO_SLOT = '2026-10-26T12:00:00Z';

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-26 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    config(['online_store.enabled' => true, 'online_store.checkout_challenge' => false, 'online_store.customer_limits' => false]);
    $this->standardPlan();
    $this->webPlan = $this->webOnlyPlan();
});

/** A portal product (Products page), hub-owned: every till pulls it later. Returns its id. */
function osoProduct(object $test, Company $company, array $ids, string $name, string $sku, string $price, string $barcode): string
{
    $test->actingAs($test->ownerOf($company), 'web')->post('/app/products', CatalogueFixtures::form($ids, [
        'name' => $name, 'short_name' => $name, 'sku' => $sku, 'sell_price' => $price,
        'barcodes' => [['id' => null, 'barcode' => $barcode, 'pack_qty' => 1, 'is_primary' => true]],
    ]))->assertSessionHasNoErrors();

    return Product::withoutCompanyScope()->where('company_id', $company->id)->where('sku', $sku)->value('id');
}

function osoInput(string $shopId, string $productId, string $quantity): CollectOrderInput
{
    return CollectOrderInput::fromArray([
        'shopId' => $shopId, 'collectFrom' => OSO_SLOT, 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test', 'lines' => [['productId' => $productId, 'quantity' => $quantity]],
    ]);
}

test('the wizard makes an online store only business: no tills or keys, its shop and store, Direct Debit, the store welcome email', function () {
    $company = $this->webOnlyBusiness($this->webPlan);
    $store = $this->storeOf($company);

    expect(Register::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(0)
        ->and(Licence::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(0)
        ->and($this->shopBranch($company)->name)->toBe('Kirkgate')
        ->and($company->plan_id)->toBe($this->webPlan->id)
        ->and($store->addon_on)->toBeTrue()->and($store->sells_without_tills)->toBeTrue()
        ->and($store->is_live)->toBeFalse()
        ->and(OnlineStoreSwitch::availableFor($company))->toBeTrue()
        ->and(OnlineStoreOnly::sellsWithoutTills($company))->toBeTrue()
        ->and($this->billingAccountOf($company)->isDirectDebit())->toBeTrue()
        ->and(MandateDeadline::applies($company, $this->billingAccountOf($company)))->toBeTrue();

    Mail::assertQueued(WelcomeTenantMail::class, fn (WelcomeTenantMail $mail) => $mail->data->tills === []
        && $mail->subjectLine() === 'Welcome to Switch & Save – your online store'
        && str_contains($mail->render(), 'Set up your online store') && ! str_contains($mail->render(), 'licence key'));

    // No till can be added while it is on the online store only plan; no licence can go on that plan.
    expect(fn () => app(AddRegister::class)->handle($this->shopBranch($company)))->toThrow(ValidationException::class);
});

test('a till count or the licence form on an online store only plan are ignored by the form and refused by the action', function () {
    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', $this->webOnlyForm($this->webPlan, ['tills' => 3, 'kind' => 'full']))
        ->assertSessionHasNoErrors();
    $company = Company::query()->where('name', 'Rosa Deli')->firstOrFail();
    expect(Register::withoutCompanyScope()->where('company_id', $company->id)->count())->toBe(0);

    $tenant = $this->newTenant('Other Deli', 2, 'OTH');
    $data = new NewTenant($tenant->company, $tenant->branch, 2, 'Owner', 'o@other.test', planId: $this->webPlan->id);
    expect(fn () => app(CreateTenant::class)->handle($data))->toThrow(ValidationException::class, 'online store only plan');
});

test('end to end: products from the portal, own stock, an order, the invoice line, then Change plan to tills and the till stock takes over', function () {
    $company = $this->webOnlyBusiness($this->webPlan);
    $shop = $this->webOnlyLive($company);
    $ids = CatalogueFixtures::seed($company);
    $tea = osoProduct($this, $company, $ids, 'Rosa Tea 80s', 'TEA-80', '2.40', '5010044000701');
    $jam = osoProduct($this, $company, $ids, 'Rosa Jam 340g', 'JAM-340', '3.10', '5010044000718');
    $oil = osoProduct($this, $company, $ids, 'Rosa Oil 1l', 'OIL-1', '6.50', '5010044000725');

    // Own stock on the products page: 2 tea counted, jam not counted (in stock), oil out of stock.
    $owner = $this->ownerOf($company);
    $this->actingAs($owner, 'web')->post("/app/online-store/products/{$tea}", ['stock_count' => '2'])->assertSessionHasNoErrors();
    $this->actingAs($owner, 'web')->post("/app/online-store/products/{$oil}", ['out_of_stock' => true])->assertSessionHasNoErrors();
    $this->actingAs($owner, 'web')->get('/app/online-store/products?shop='.$shop->branch_id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('manualStock', true)
            ->where('products.data', fn ($rows) => collect($rows)->firstWhere('id', $tea)['stockCount'] === '2'
                && collect($rows)->firstWhere('id', $oil)['outOfStock'] === true));

    $store = app(FindStoreBySlug::class)->handle($this->storeOf($company)->slug);
    expect($store)->not->toBeNull();
    $catalogue = app(StorefrontCatalogue::class);
    $items = $catalogue->items($store, $shop, [$tea, $jam, $oil]);

    // The business price (no shop price needed); the shop's own stock, never the tills' tables.
    expect($items[$tea]->price)->toBe('2.40')->and($items[$tea]->stockTracked)->toBeTrue()->and($items[$tea]->stockLimit)->toBe('2.0000')
        ->and($items[$jam]->orderable)->toBeTrue()->and($items[$jam]->stockLimit)->toBeNull()
        ->and($items[$oil]->orderable)->toBeFalse()
        ->and(collect($catalogue->products($store, $shop, filters: new CatalogueFilters(inStockOnly: true))->items)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$tea, $jam])->sort()->values()->all())
        ->and(DB::table('branch_products')->where('company_id', $company->id)->count())->toBe(0);

    // An order for both teas: held while open, then taken off the count when collected (undo puts it back).
    $order = app(PlaceCollectOrder::class)->handle($store, osoInput($shop->id, $tea, '2'))->order;
    expect($order->items_total)->toBe('4.80')
        ->and($catalogue->items($store, $shop, [$tea])[$tea]->stockLimit)->toBe('0.0000');
    expect(fn () => app(PlaceCollectOrder::class)->handle($store, osoInput($shop->id, $tea, '1')))->toThrow(ValidationException::class);

    foreach ([OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::Collected] as $status) {
        app(ChangeOnlineOrderStatus::class)->handle($company, $order->id, $status);
    }
    $count = fn () => OnlineStoreProduct::withoutCompanyScope()->where('company_id', $company->id)->where('product_id', $tea)->value('stock_count');
    expect((float) $count())->toBe(0.0);
    app(UndoOnlineOrderStatus::class)->handle($company, $order->id);
    expect((float) $count())->toBe(2.0);
    app(ChangeOnlineOrderStatus::class)->handle($company, $order->id, OnlineOrderStatus::Collected);
    expect((float) $count())->toBe(0.0);

    // Billing: the single "Online store" line, £30 + VAT, collected by Direct Debit.
    $invoice = $this->draftFor($company);
    expect($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines[0]->kind?->value ?? $invoice->lines[0]->kind)->toBe('onlineStore')
        ->and($invoice->lines[0]->description)->toStartWith('Online store · ')
        ->and([$invoice->subtotal, $invoice->vat_total, $invoice->total])->toBe(['30.00', '6.00', '36.00']);
    $invoice->delete();
    $this->setUpMandate($company);
    expect($this->billingAccountOf($company)->gc_subscription_amount)->toBe('36.00');

    // Change plan to Setup + monthly with the online store (£20) and cloud sync.
    $epos = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '300.00', '25.00', [Feature::Loyalty, Feature::CloudSync]);
    $epos->forceFill(['includes_online_store' => true, 'online_store_monthly_price' => '20.00'])->save();
    $preview = $this->previewPlan($company, $epos)->assertOk()->json();
    $words = implode(' ', [...$preview['onlineStore']['lines'], ...$preview['licences']['lines']]);
    expect($preview['blocked'])->toBeNull()->and($preview['onlineStore']['change'])->toBe('stays')
        ->and($words)->toContain('Until its first till is live the store keeps selling the products made in the portal')
        ->and($words)->toContain('uses the tills’ stock and prices automatically')
        ->and($words)->toContain('No tills yet (online store only)');

    $this->changePlan($company, $epos, ['setup_fee' => '0'])->assertSessionHasNoErrors();
    $company->refresh();
    expect($company->plan_id)->toBe($epos->id)
        ->and(OnlineStoreSwitch::availableFor($company))->toBeTrue()
        ->and(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBe('20.00')
        ->and(SubscriptionAmount::for($company, $this->billingAccountOf($company))['gross'])->toBe('24.00')
        ->and($catalogue->items($store, $shop, [$jam])[$jam]->orderable)->toBeTrue();

    // A till: its licence on the new plan; the store now reads the tills' stock (none synced yet = 0), then the till's.
    app(AddRegister::class)->handle($this->shopBranch($company));
    expect(Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->sole()->plan_id)->toBe($epos->id)
        ->and(OnlineStoreOnly::sellsWithoutTills($company))->toBeFalse()
        ->and(OnlineStoreSwitch::availableFor($company))->toBeTrue()
        ->and($catalogue->items($store, $shop, [$jam])[$jam]->orderable)->toBeFalse();
    DB::table('branch_products')->insert(['id' => Ulid::new(), 'company_id' => $company->id, 'branch_id' => $shop->branch_id,
        'product_id' => $jam, 'is_active' => true, 'qty_on_hand' => '7', 'qty_reserved' => '0', 'row_version' => 1]);
    expect($catalogue->items($store, $shop, [$jam])[$jam]->stockLimit)->toBe('7.0000')
        ->and($catalogue->items($store, $shop, [$oil])[$oil]->orderable)->toBeFalse();

    $lines = $this->draftFor($company)->lines;
    expect($lines)->toHaveCount(2)->and($lines->last()->description)->toStartWith('Online store · ')->and($lines->last()->unit_price)->toBe('20.00');
});

test('availability needs the switch, the country, the add-on and a recurring plan; Pakistan has none of it', function () {
    $company = $this->webOnlyBusiness($this->webPlan);
    expect(OnlineStoreSwitch::availableFor($company))->toBeTrue();

    config(['online_store.enabled' => false]);
    expect(OnlineStoreSwitch::availableFor($company))->toBeFalse();
    config(['online_store.enabled' => true]);

    $this->storeOf($company)->forceFill(['addon_on' => false])->save();
    expect(OnlineStoreSwitch::availableFor($company->refresh()))->toBeFalse()
        ->and(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull();
    $this->storeOf($company)->forceFill(['addon_on' => true])->save();

    OnlineStoreFixtures::asPakistan();
    expect(OnlineStoreSwitch::availableFor($company))->toBeFalse()
        ->and(CompanyPricing::for($company, $this->billingAccountOf($company))->onlineStoreMonthly)->toBeNull();
});

test('isolation: one online store only business never sees or changes another business’s products or stock', function () {
    $a = $this->webOnlyBusiness($this->webPlan);
    $b = $this->webOnlyBusiness($this->webPlan, 'Marco Deli', 'marco@marcodeli.test');
    $shopA = $this->webOnlyLive($a);
    $this->webOnlyLive($b);
    $idsB = CatalogueFixtures::seed($b);
    $theirs = osoProduct($this, $b, $idsB, 'Marco Pasta', 'PAS-500', '1.80', '5010044000732');

    $this->actingAs($this->ownerOf($a), 'web')->post("/app/online-store/products/{$theirs}", ['stock_count' => '50'])->assertSessionHasErrors('product_id');
    $this->actingAs($this->ownerOf($a), 'web')->get('/app/online-store/products?search=Marco')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('products.data', []));

    $storeA = app(FindStoreBySlug::class)->handle($this->storeOf($a)->slug);
    expect(app(StorefrontCatalogue::class)->items($storeA, $shopA, [$theirs]))->toBe([])
        ->and(OnlineStoreProduct::withoutCompanyScope()->where('product_id', $theirs)->exists())->toBeFalse();
});

test('with welcome emails held, the store welcome email waits on the business’s Emails tab', function () {
    Mail::swap(new MailManager(app()));
    config(['mail.default' => 'array']);
    app(UpdateEmailSettings::class)->handle(array_fill_keys(EmailCategory::values(), false));

    $this->actingAs($this->admin(), 'admin')->post('/admin/tenants', $this->webOnlyForm($this->webPlan))
        ->assertSessionHas('success', 'Rosa Deli is set up with the online store only (no tills). Its welcome email is held: check the business, then send it here.');
    $company = Company::query()->where('name', 'Rosa Deli')->firstOrFail();

    expect(HeldEmail::query()->where('company_id', $company->id)->where('template', WelcomeTenantMail::templateKey())->count())->toBe(1);
});
