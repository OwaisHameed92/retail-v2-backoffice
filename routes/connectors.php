<?php

// W5: the BikeBook POS connector (docs/verticals-0.1.85.md "BikeBook connector"; EPOS contract
// docs/contracts/portal-api-0.1.85/docs/web-portal-api/WORKSHOP-BIKEBOOK.md §1–§3). Every route answers 404 while
// WORKSHOP_CONNECTOR is off (`workshop.connector`). Paths are BikeBook's own POS connector names.

use App\Http\Controllers\Api\Workshop\CatalogueController;
use App\Http\Controllers\Api\Workshop\CustomerController;
use App\Http\Controllers\Api\Workshop\OAuthTokenController;
use App\Http\Controllers\Api\Workshop\SalesOrderController;
use App\Http\Controllers\Api\Workshop\WebhookController;
use App\Http\Controllers\App\WorkshopAuthorizeController;
use App\Http\Middleware\AuthenticateWorkshopConnector;
use Illuminate\Support\Facades\Route;

// OAuth 2.0 consent: the shop owner, signed in to the tenant portal (owner: business.manage), picks the shop.
Route::middleware(['web', 'workshop.connector', 'auth:web', 'verified', 'company', 'two-factor:web', 'company.can:business.manage'])
    ->prefix('oauth')->name('workshop.oauth.')->controller(WorkshopAuthorizeController::class)->group(function () {
        Route::get('authorize', 'show')->name('authorize');
        Route::post('authorize', 'store')->name('authorize.store')->middleware('throttle:20,1');
    });

// OAuth 2.0 token endpoint for BikeBook's servers (client id + secret; no session, no CSRF).
Route::middleware(['api', 'workshop.connector', 'throttle:60,1'])->post('oauth/token', OAuthTokenController::class)->name('workshop.oauth.token');

// The connector itself: Bearer token or ApiKey header, tenant-id, 600 a minute per connection, every call logged.
Route::middleware(['api', 'workshop.connector', AuthenticateWorkshopConnector::class])
    ->prefix('connectors/bikebook/v1/api')->name('workshop.connector.')->group(function () {
        Route::get('Stores', [CatalogueController::class, 'stores'])->name('stores');
        Route::get('TaxRates', [CatalogueController::class, 'taxRates'])->name('tax-rates');
        Route::get('Products', [CatalogueController::class, 'products'])->name('products');
        Route::get('Product/{id}', [CatalogueController::class, 'product'])->name('product')->where('id', '[A-Za-z0-9_-]{1,64}');
        Route::get('Inventory', [CatalogueController::class, 'inventory'])->name('inventory');
        Route::post('Inventory/batch', [CatalogueController::class, 'inventoryBatch'])->name('inventory.batch');

        Route::get('Customer/list', [CustomerController::class, 'index'])->name('customers');
        Route::get('Customer/{id}', [CustomerController::class, 'show'])->name('customer')->where('id', '[A-Za-z0-9_-]{1,64}');
        Route::post('Customer', [CustomerController::class, 'store'])->name('customer.store');
        Route::put('Customer', [CustomerController::class, 'update'])->name('customer.update');
        Route::put('Customer/{id}/Address', [CustomerController::class, 'address'])->name('customer.address')->where('id', '[A-Za-z0-9_-]{1,64}');

        Route::post('SalesOrder', [SalesOrderController::class, 'store'])->name('sales-order.store');
        Route::get('SalesOrder', [SalesOrderController::class, 'show'])->name('sales-order');
        Route::put('SalesOrder', [SalesOrderController::class, 'update'])->name('sales-order.update');
        Route::delete('SalesOrder', [SalesOrderController::class, 'destroy'])->name('sales-order.destroy');
        Route::put('SalesOrder/{id}/status/{status}', [SalesOrderController::class, 'status'])->name('sales-order.status')
            ->where(['id' => '[A-Za-z0-9_-]{1,64}', 'status' => '[A-Za-z]{1,20}']);
        Route::post('SalesOrderLine', [SalesOrderController::class, 'storeLine'])->name('sales-order-line.store');
        Route::put('SalesOrderLine', [SalesOrderController::class, 'updateLine'])->name('sales-order-line.update');
        Route::delete('SalesOrderLine', [SalesOrderController::class, 'destroyLine'])->name('sales-order-line.destroy');

        Route::get('Webhook', [WebhookController::class, 'index'])->name('webhooks');
        Route::get('Webhook/{id}', [WebhookController::class, 'show'])->name('webhook')->where('id', '[A-Za-z0-9_-]{1,100}');
        Route::post('Webhook', [WebhookController::class, 'store'])->name('webhook.store');
        Route::put('Webhook', [WebhookController::class, 'update'])->name('webhook.update');
        Route::delete('Webhook/{id}', [WebhookController::class, 'destroy'])->name('webhook.destroy')->where('id', '[A-Za-z0-9_-]{1,100}');
    });
