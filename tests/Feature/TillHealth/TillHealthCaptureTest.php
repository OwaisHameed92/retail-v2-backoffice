<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/** Module 2.7: what licence/activate and licence/validate now keep for Till health. */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
    $this->token = (string) $this->activateTill()->assertOk()->json('licenceToken');
});

test('activate records the contract version the till speaks', function () {
    expect($this->licence->fresh()->last_contract_version)->toBe('1')
        ->and($this->licence->fresh()->diagnostics_at)->toBeNull();
});

test('validate keeps only the known diagnostics members and when they came', function () {
    $this->travel(1)->days();

    $this->validateTill($this->licence->id, $this->token, overrides: ['diagnostics' => [
        'pendingSyncRows' => 42,
        'lastSyncError' => '  401 auth.invalid_key  ',
        'databaseSizeMb' => 812.44,
        'apiKey' => 'SSK-SECRET-VALUE',
        'connectionString' => 'Server=.;Password=hunter2',
    ]])->assertOk();

    $licence = $this->licence->fresh();
    expect($licence->diagnostics)->toBe(['pendingSyncRows' => 42, 'lastSyncError' => '401 auth.invalid_key', 'databaseSizeMb' => 812.4])
        ->and($licence->diagnostics_at?->toIso8601String())->toBe('2026-10-06T09:00:00+00:00')
        ->and(json_encode($licence->getAttributes()))->not->toContain('hunter2')->not->toContain('SSK-SECRET');
});

test('a validate without diagnostics leaves the last report in place; nonsense values are dropped', function () {
    $this->validateTill($this->licence->id, $this->token, overrides: ['diagnostics' => ['pendingSyncRows' => 5]])->assertOk();
    $this->travel(1)->hours();
    $this->validateTill($this->licence->id, $this->token)->assertOk();
    expect($this->licence->fresh()->diagnostics)->toBe(['pendingSyncRows' => 5]);

    $this->validateTill($this->licence->id, $this->token, overrides: ['diagnostics' => ['pendingSyncRows' => -3, 'databaseSizeMb' => 'big']])->assertOk();
    expect($this->licence->fresh()->diagnostics)->toBeNull()
        ->and($this->licence->fresh()->diagnostics_at)->not->toBeNull();
});
