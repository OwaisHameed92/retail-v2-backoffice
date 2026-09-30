<?php

use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
    $this->token = (string) $this->activateTill()->assertOk()->json('licenceToken');
});

test('a till releases its key by its own registerId; the key can then be activated elsewhere', function () {
    $this->deactivateTill()->assertOk()
        ->assertExactJson([
            'registerId' => self::TILL_REGISTER,
            'seat' => 'deactivated',
            'seatsInUse' => 0,
            'maxRegisters' => 2,
            'apiKeyRevoked' => false,
            'transferCode' => null,
            'transferCodeExpiresAt' => null,
            'portalTimeUtc' => '2026-10-05T09:00:00Z',
            'messages' => [],
        ]);

    expect($this->licence->fresh()->device_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'licence.released')->sole()->meta)->toMatchArray(['reason' => 'removed']);

    $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('status', 'released');
    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();
});

test('deactivating twice gives the same reply', function () {
    $first = $this->deactivateTill()->assertOk()->json();
    $second = $this->deactivateTill()->assertOk()->json();

    expect($second)->toBe($first)
        ->and(AuditLog::query()->where('action', 'licence.released')->count())->toBe(1);
});

test('our own register id works too', function () {
    $this->deactivateTill($this->licence->register_id)->assertOk()->assertJsonPath('registerId', $this->licence->register_id);

    expect($this->licence->fresh()->device_id)->toBeNull();
});

test('an unknown register or install is 404 device.not_found', function () {
    $this->deactivateTill('01K5T0Q8C4000000000000R999')->assertNotFound()->assertJsonPath('code', 'device.not_found');
    $this->deactivateTill(self::TILL_REGISTER, self::OTHER_INSTALL)->assertNotFound()->assertJsonPath('code', 'device.not_found');

    expect($this->licence->fresh()->device_id)->toBe(self::INSTALL);
});

test('ANSWERS-2026-09-30-portal point 6: the main till gets no transfer code, apiKeyRevoked true, a next step, and the same key activates on a new PC', function () {
    $this->deactivateTill()->assertOk();
    $this->licence->forceFill(['features' => ['cloud_sync']])->save();
    expect($this->activateTill()->assertOk()->json('apiKey'))->toStartWith('SSK-');

    $reply = $this->deactivateTill()->assertOk();

    expect($reply->json())->toMatchArray(['apiKeyRevoked' => true, 'transferCode' => null, 'transferCodeExpiresAt' => null])
        ->and($reply->json('messages'))->toHaveCount(1)
        ->and($reply->json('messages.0'))->toMatchArray(['level' => 'info', 'title' => 'Till released', 'dismissible' => false])
        ->and($reply->json('messages.0.text'))->toContain('same licence key under Settings → Licence')
        ->and($this->deactivateTill()->assertOk()->json())->toBe($reply->json());   // idempotent

    $moved = $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();

    expect($this->licence->fresh()->device_id)->toBe(self::OTHER_INSTALL)
        ->and($moved->json('apiKey'))->toStartWith('SSK-');
});
