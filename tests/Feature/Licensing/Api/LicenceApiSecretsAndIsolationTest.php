<?php

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Licensing\Models\LicenceDevice;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
});

test('keys are never logged, stored or echoed, even on errors and alerts', function () {
    config(['logging.default' => 'null']);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    [, $licence] = $this->keyedTenant();
    $replies = [
        $this->activateTill()->getContent(),
        $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->getContent(), // 409 + alert (logs)
        $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL)->getContent(),
        $this->till('licence/activate', ['licenceKey' => self::KEY])->getContent(),
    ];

    $haystack = implode("\n", [...$logged, ...$replies, $this->storedText(), json_encode(DB::table('licence_devices')->get()), json_encode(DB::table('licence_alerts')->get())]);
    $bodies = [LicenceKey::parse(self::KEY)->body(), LicenceKey::parse(self::OTHER_KEY)->body()];

    expect(LicenceAlert::withoutCompanyScope()->count())->toBe(1)->and($logged)->not->toBeEmpty();
    foreach ($bodies as $body) {
        expect(str_contains($haystack, $body))->toBeFalse()
            ->and(str_contains($haystack, implode('-', str_split($body, 4))))->toBeFalse();
    }

    // Install ids of other PCs are kept only as hashes.
    expect(json_encode(DB::table('licence_devices')->get()))->not->toContain(self::OTHER_INSTALL);
});

test('a key binds only its own company licence and the token names only that company', function () {
    [$a, $licenceA] = $this->keyedTenant('Khan Mini Mart', 2, 'LDS');
    [$b, $licenceB] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);

    $reply = $this->activateTill()->assertOk();

    expect($reply->json('licence.companyId'))->toBe($a->id)
        ->and((string) $reply->getContent())->not->toContain($b->id)
        ->and($licenceB->fresh()->device_id)->toBeNull()
        ->and(LicenceDevice::withoutCompanyScope()->where('company_id', $b->id)->count())->toBe(0);
});

test('company B cannot validate or release company A licence', function () {
    [, $licenceA] = $this->keyedTenant('Khan Mini Mart', 2, 'LDS');
    [, $licenceB] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);
    $tokenA = $this->activateTill()->json('licenceToken');
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk();

    // B's till asks about A's licence id: not bound to it.
    $this->validateTill($licenceA->id, $tokenA, self::OTHER_INSTALL)->assertNotFound();
    // B's till tries to release A's till.
    $this->deactivateTill($licenceA->register_id, self::OTHER_INSTALL)->assertNotFound();

    expect($licenceA->fresh()->device_id)->toBe(self::INSTALL)
        ->and($licenceB->fresh()->device_id)->toBe(self::OTHER_INSTALL);
});
