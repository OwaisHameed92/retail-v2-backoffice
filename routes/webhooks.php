<?php

use App\Http\Controllers\DirectDebit\DirectDebitSetupController;
use App\Http\Controllers\Webhooks\GoCardlessWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Loaded without the web middleware (no session, no CSRF): GoCardless webhooks (module 1.12), authenticated by
| their HMAC signature, and the signed Direct Debit links from our emails.
*/

Route::post('webhooks/gocardless', GoCardlessWebhookController::class)
    ->middleware(['billing.direct-debit', 'throttle:120,1'])
    ->name('webhooks.gocardless');

Route::middleware(['billing.direct-debit', 'web', 'signed', 'throttle:30,1'])->prefix('direct-debit/{company}')->name('direct-debit.')->whereUlid('company')->group(function () {
    Route::get('setup', [DirectDebitSetupController::class, 'setup'])->name('setup');
    Route::get('done', [DirectDebitSetupController::class, 'done'])->name('done');
});
