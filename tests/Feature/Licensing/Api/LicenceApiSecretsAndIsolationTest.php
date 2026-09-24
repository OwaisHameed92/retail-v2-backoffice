<?php

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\LicenceAlert;
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
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
    $this->withSigningKey();
});

test('the key never reaches the logs, the audit log, the database or an error reply', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    });

    [, $licence] = $this->keyedTenant();
    $replies = [
        $this->till('activate', $this->activateBody())->assertOk(),
        $this->till('activate', $this->activateBody(device: self::OTHER_PC))->assertStatus(409),
        $this->till('check-in', $this->checkInBody())->assertOk(),
        $this->till('check-in', $this->checkInBody(device: self::OTHER_PC))->assertForbidden(),
        $this->till('check-in', $this->checkInBody(key: self::OTHER_KEY))->assertNotFound(),
        $this->till('check-in', ['licenceKey' => self::KEY])->assertStatus(400),
        $this->till('check-in', ['licenceKey' => 'SSP-7K2Q-9DMF-3XRA-P8T6', 'deviceId' => self::PC])->assertNotFound(),
        $this->postJson('/api/v1/licence/check-in?licenceKey='.self::KEY, [], $this->tillHeaders())->assertStatus(400),
        $this->till('deactivate', ['licenceKey' => self::KEY, 'deviceId' => self::PC])->assertOk(),
    ];

    $tables = ['licences', 'audit_logs', 'email_logs', 'licence_alerts', 'licence_devices', 'retired_licence_keys', 'companies', 'jobs', 'failed_jobs'];
    $stored = collect($tables)->map(fn (string $table) => json_encode(DB::table($table)->get()->all()))->implode("\n");
    $text = $stored."\n".implode("\n", $logged)."\n".collect($replies)->map(fn ($r) => $r->getContent())->implode("\n");

    $body = LicenceKey::parse(self::KEY)->body();
    foreach ([self::KEY, $body, strtolower($body), '7K2Q-9DMF-3XRA'] as $needle) {
        expect($text)->not->toContain($needle);
    }

    // Other PCs' device ids are only kept hashed.
    expect($stored)->not->toContain(self::OTHER_PC)
        ->and($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->toContain('SSP-••••-••••-••••-P8T5');
    expect($licence->refresh()->key_last4)->toBe('P8T5');
});

test('a key only ever exposes its own company, branch and till', function () {
    [$khan, $khanLicence] = $this->keyedTenant('Khan Mini Mart', 2, 'LDS');
    [$patel, $patelLicence] = $this->keyedTenant('Patel News', 1, 'PTL', self::OTHER_KEY);

    $reply = $this->till('activate', $this->activateBody(key: self::OTHER_KEY))
        ->assertOk()
        ->assertJsonPath('company.id', $patel->id)
        ->assertJsonPath('branch.code', 'PTL')
        ->assertJsonPath('register.id', $patelLicence->register_id)
        ->assertJsonPath('licence.id', $patelLicence->id);

    $content = (string) $reply->getContent();
    expect($content)->not->toContain($khan->id)
        ->not->toContain($khanLicence->id)
        ->not->toContain($khanLicence->register_id)
        ->not->toContain('Khan Mini Mart')
        ->and($this->verifyToken($reply)->claim('companyId'))->toBe($patel->id);

    // Patel's PC cannot check in with Khan's key, and an alert on Khan's licence stays Khan's.
    $this->till('check-in', $this->checkInBody(key: self::KEY))->assertForbidden();
    $this->till('activate', $this->activateBody())->assertOk()->assertJsonPath('company.id', $khan->id);
    $this->till('activate', $this->activateBody(device: 'PATEL-PC-2'))->assertStatus(409);

    expect(LicenceAlert::withoutCompanyScope()->pluck('company_id')->unique()->all())->toBe([$khan->id]);
    expect($patelLicence->refresh()->device_id)->toBe(self::PC)
        ->and($khanLicence->refresh()->device_id)->toBe(self::PC);
});
