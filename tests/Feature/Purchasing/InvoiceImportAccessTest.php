<?php

use App\Domain\Ai\Models\AiUsage;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\InvoiceImportFixtures as I;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/** Module 6.5: plan feature, no key (manual entry), file rules, roles, one-shop users and company isolation. */
beforeEach(function () {
    $this->travelTo('2026-09-24 08:30:00');
    Storage::fake('local');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    I::plan($this->company);
    I::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->fake = I::fakeAi();
    $this->import = fn () => InvoiceImport::withoutCompanyScope()->where('company_id', $this->company->id)->latest('id')->firstOrFail();
    $this->upload = fn ($user, array $input = []) => $this->actingAs($user)->post('/app/purchasing/invoices/import', ['shopId' => $this->sync->leeds->id, 'file' => I::pdf(), ...$input]);
});

test('without assist_invoice_scan in the plan the screen says so and every change is refused', function () {
    I::plan($this->company, withScan: false);

    $this->actingAs($this->owner)->get('/app/purchasing/invoices/import')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/purchasing/invoice-import')->where('access.inPlan', false)->where('access.reader.available', false));

    ($this->upload)($this->owner)->assertForbidden();
    ($this->upload)($this->owner, ['manual' => true, 'file' => null])->assertForbidden();
    expect(InvoiceImport::withoutCompanyScope()->count())->toBe(0)->and($this->fake->requests)->toBe([]);
});

test('with no API key the screen offers entering by hand; the invoice is matched and confirmed without any model call', function () {
    $this->fake->notConfigured();

    $this->actingAs($this->owner)->get('/app/purchasing/invoices/import')->assertInertia(fn (Assert $page) => $page
        ->where('access.inPlan', true)->where('access.reader.available', false)->where('access.reader.message', fn ($m) => is_string($m) && $m !== ''));

    // A file still uploads (kept for the record), but nobody reads it: an empty draft to fill in.
    ($this->upload)($this->owner)->assertSessionHasNoErrors();
    $import = ($this->import)();
    expect($import->method)->toBe('manual')->and($import->status->value)->toBe('review')->and($import->draft['lines'])->toBe([]);

    $this->actingAs($this->owner)->put("/app/purchasing/invoices/import/{$import->id}", [
        'documentType' => 'invoice', 'supplierName' => 'Aire Valley Cash and Carry', 'invoiceNumber' => 'AV-77', 'invoiceDate' => '2026-09-22',
        'netTotal' => '24.00', 'vatTotal' => '0.00', 'grossTotal' => '24.00', 'supplierPinned' => false, 'documentPinned' => false,
        'lines' => [['description' => 'Toastie bread', 'barcode' => '0400001042175', 'quantity' => '2', 'packSize' => 12, 'unitPrice' => '12.00', 'vatRate' => '0', 'lineNet' => '24.00', 'pinned' => false]],
    ])->assertSessionHasNoErrors();

    $import->refresh();
    expect($import->supplier_id)->toBe(F::SUPPLIER)->and($import->draft['lines'][0]['productId'])->toBe(F::WATER);

    $this->actingAs($this->owner)->post("/app/purchasing/invoices/import/{$import->id}/confirm", ['order' => 'none'])->assertSessionHasNoErrors();
    expect($import->fresh()->status->value)->toBe('confirmed')->and($this->fake->requests)->toBe([])
        ->and(AiUsage::query()->count())->toBe(0)->and(PurchaseOrder::withoutCompanyScope()->count())->toBe(0);

    // A manual entry needs no file at all.
    ($this->upload)($this->owner, ['manual' => true, 'file' => null])->assertSessionHasNoErrors();
    expect(($this->import)()->file_path)->toBeNull();
});

test('only a PDF, JPG or PNG up to 10 MB is accepted, checked by content', function () {
    ($this->upload)($this->owner, ['file' => UploadedFile::fake()->create('invoice.txt', 10, 'text/plain')])->assertSessionHasErrors('file');
    ($this->upload)($this->owner, ['file' => I::real('invoice.pdf', "MZ\x90\x00 not really a pdf")])->assertSessionHasErrors('file');
    ($this->upload)($this->owner, ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
    ($this->upload)($this->owner, ['file' => null])->assertSessionHasErrors('file');
    ($this->upload)($this->owner, ['shopId' => 'nope'])->assertSessionHasErrors('shopId');
    expect(InvoiceImport::withoutCompanyScope()->count())->toBe(0)->and($this->fake->requests)->toBe([]);
});

test('guests are sent to log in; roles without purchasing.manage get 403', function () {
    $this->get('/app/purchasing/invoices/import')->assertRedirect('/login');

    foreach ([CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $user = F::member($this->company, $role);
        $this->actingAs($user)->get('/app/purchasing/invoices/import')->assertForbidden();
        ($this->upload)($user)->assertForbidden();
    }

    $this->fake->callTool('record_invoice', I::extraction());
    ($this->upload)($this->owner);
    $import = ($this->import)();
    $accountant = F::member($this->company, CompanyRole::Accountant);

    foreach (['get' => ['', '/file'], 'post' => ['/confirm', '/retry', '/discard'], 'put' => ['']] as $method => $paths) {
        foreach ($paths as $path) {
            $this->actingAs($accountant)->{$method}("/app/purchasing/invoices/import/{$import->id}{$path}")->assertForbidden();
        }
    }

    // A manager of every shop has purchasing.manage and catalogue.manage: they may review, order and change costs.
    $manager = F::member($this->company, CompanyRole::Manager);
    $this->actingAs($manager)->get("/app/purchasing/invoices/import/{$import->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.order', true)->where('can.costs', true));
});

test('a one-shop manager imports for their own shop only, sees only its imports and cannot order or change costs', function () {
    $leedsOnly = F::member($this->company, CompanyRole::Manager, $this->sync->leeds);
    $this->fake->callTool('record_invoice', I::extraction())->callTool('record_invoice', I::extraction(['invoiceNumber' => 'BFD-1']));

    ($this->upload)($leedsOnly, ['shopId' => $this->sync->bradford->id])->assertForbidden();
    ($this->upload)($this->owner, ['shopId' => $this->sync->bradford->id])->assertSessionHasNoErrors();
    $bradford = ($this->import)();
    ($this->upload)($leedsOnly)->assertSessionHasNoErrors();
    $leeds = ($this->import)();

    $this->actingAs($leedsOnly)->get('/app/purchasing/invoices/import')->assertInertia(fn (Assert $page) => $page
        ->where('access.oneShop', true)->has('shops', 1)->where('imports.meta.total', 1)->where('imports.data.0.id', $leeds->id));
    $this->actingAs($leedsOnly)->get("/app/purchasing/invoices/import/{$bradford->id}")->assertNotFound();
    $this->actingAs($leedsOnly)->get("/app/purchasing/invoices/import/{$leeds->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.order', false)->where('can.costs', false)->where('can.confirm', true));

    $this->actingAs($leedsOnly)->post("/app/purchasing/invoices/import/{$leeds->id}/confirm", ['order' => 'draft', 'acknowledged' => true])->assertForbidden();
    $this->actingAs($leedsOnly)->post("/app/purchasing/invoices/import/{$leeds->id}/confirm", ['costUpdates' => [F::WATER], 'acknowledged' => true])->assertForbidden();
    $this->actingAs($leedsOnly)->post("/app/purchasing/invoices/import/{$leeds->id}/confirm", ['acknowledged' => true])->assertSessionHasNoErrors();
    expect($leeds->fresh()->status->value)->toBe('confirmed')->and($leeds->fresh()->purchase_order_id)->toBeNull();
});

test('another business cannot see, download, change or confirm an import', function () {
    $this->fake->callTool('record_invoice', I::extraction());
    ($this->upload)($this->owner);
    $import = ($this->import)();

    app(CurrentCompany::class)->forget();
    $other = Company::factory()->withBranch('OTH', 'Other shop')->create(['name' => 'Other Mart']);
    I::plan($other);
    $stranger = F::member($other, CompanyRole::Owner);

    $this->actingAs($stranger)->get('/app/purchasing/invoices/import')->assertOk()->assertInertia(fn (Assert $page) => $page->where('imports.meta.total', 0));
    $this->actingAs($stranger)->get("/app/purchasing/invoices/import/{$import->id}")->assertNotFound();
    $this->actingAs($stranger)->get("/app/purchasing/invoices/import/{$import->id}/file")->assertNotFound();
    $this->actingAs($stranger)->put("/app/purchasing/invoices/import/{$import->id}", ['documentType' => 'invoice', 'lines' => []])->assertNotFound();
    $this->actingAs($stranger)->post("/app/purchasing/invoices/import/{$import->id}/confirm", ['acknowledged' => true])->assertNotFound();
    $this->actingAs($stranger)->post("/app/purchasing/invoices/import/{$import->id}/discard")->assertNotFound();

    // Their own upload never matches our supplier or products.
    $this->fake->callTool('record_invoice', I::extraction());
    $shop = app(CurrentCompany::class)->runAs($other, fn () => Branch::query()->firstOrFail());
    $this->actingAs($stranger)->post('/app/purchasing/invoices/import', ['shopId' => $shop->id, 'file' => I::pdf()])->assertSessionHasNoErrors();
    $theirs = InvoiceImport::withoutCompanyScope()->where('company_id', $other->id)->firstOrFail();
    expect($theirs->supplier_id)->toBeNull()->and(array_filter(array_column($theirs->draft['lines'], 'productId')))->toBe([])
        ->and($import->fresh()->status->value)->toBe('review');
});
