<?php

use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Api\Simulator\SimulatedTill;
use App\Domain\Licensing\Signing\KeyStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * `licence:simulate` against this app: Http::fake hands every request the command makes to the app's own HTTP
 * kernel, so the real routes, middleware and actions answer, and the command verifies the token with the JWKS
 * it fetched over "HTTP", like a till.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test']);

    Http::fake(function (ClientRequest $request) {
        expect($request->url())->toStartWith('https://portal.test/api/v1/licence/');

        $headers = collect($request->headers())->map(fn (array $values) => $values[0])->all();
        $response = $this->call(
            $request->method(),
            (string) parse_url($request->url(), PHP_URL_PATH),
            [], [], [],
            $this->transformHeadersToServerVars($headers),
            $request->body(),
        );

        return Http::response((string) $response->getContent(), $response->getStatusCode(), $response->headers->all());
    });
});

test('activate then check-in: the till verifies the token and trades', function () {
    [, $licence] = $this->keyedTenant();

    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1'])
        ->expectsOutputToContain('SSP-••••-••••-••••-P8T5')
        ->expectsOutputToContain('HTTP 200')
        ->expectsOutputToContain('"status": "trial"')
        ->expectsOutputToContain('Till: TRADE. Trial licence.')
        ->doesntExpectOutputToContain(self::KEY)
        ->assertSuccessful();

    expect($licence->refresh()->device_id)->toBe('DEMO-PC-1')
        ->and($licence->last_app_version)->toBe('1.0.0-simulator');

    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1', '--action' => 'check-in'])
        ->expectsOutputToContain(app(KeyStore::class)->active()->kid.' is in the JWKS')
        ->expectsOutputToContain('Till: TRADE.')
        ->assertSuccessful();

    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('X-SSPOS-Licence-Contract', '1')
        && ! str_contains($request->url(), 'SSP'));
});

test('grace shows a banner, suspended locks', function () {
    [, $licence] = $this->keyedTenant();
    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1'])->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1', '--action' => 'check-in'])
        ->expectsOutputToContain('Till: TRADE WITH GRACE BANNER.')
        ->assertSuccessful();

    // Less than 3 days of token left: the till also warns staff.
    $decision = SimulatedTill::decide(['status' => 'grace', 'validUntil' => '2026-10-04T09:00:00Z'], true, CarbonImmutable::now());
    expect($decision['decision'])->toBe('grace banner')->and($decision['reason'])->toContain('Warn staff');

    app(SuspendLicence::class)->handle($licence->refresh(), 'Unpaid invoice');
    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1', '--action' => 'check-in'])
        ->expectsOutputToContain('Till: LOCK. Status suspended')
        ->assertSuccessful();
});

test('errors are explained the way the till would handle them', function () {
    $this->keyedTenant();
    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1'])->assertSuccessful();

    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-2'])
        ->expectsOutputToContain('HTTP 409')
        ->expectsOutputToContain('licence.bound_to_other_device')
        ->assertFailed();

    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-2', '--action' => 'check-in'])
        ->expectsOutputToContain('keep trading on the last token until its validUntil')
        ->assertFailed();

    $this->artisan('licence:simulate', ['key' => self::OTHER_KEY])->expectsOutputToContain('licence.not_found')->assertFailed();

    $this->artisan('licence:simulate', ['key' => self::KEY, '--device' => 'DEMO-PC-1', '--action' => 'deactivate'])
        ->expectsOutputToContain('"released": true')
        ->assertSuccessful();

    $this->artisan('licence:simulate', ['key' => self::KEY, '--action' => 'dance'])->assertExitCode(2);
});

test('a token for another PC fails the device check', function () {
    $this->keyedTenant();
    $till = new SimulatedTill('https://portal.test', 'DEMO-PC-9', 'X');
    $reply = $this->till('activate', $this->activateBody())->assertOk();

    $result = $till->verify((string) $reply->json('token'), $till->publicKeys(), CarbonImmutable::now());
    $tampered = $till->verify(substr((string) $reply->json('token'), 0, -2).'AA', $till->publicKeys(), CarbonImmutable::now());

    expect($result['valid'])->toBeFalse()
        ->and(collect($result['checks'])->firstWhere('check', 'deviceId')['ok'])->toBeFalse()
        ->and(collect($result['checks'])->firstWhere('check', 'signature')['ok'])->toBeTrue()
        ->and($tampered['valid'])->toBeFalse();
});
