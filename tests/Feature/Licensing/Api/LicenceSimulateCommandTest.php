<?php

use App\Domain\Licensing\Actions\SuspendLicence;
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
 * kernel, so the real routes, middleware and actions answer, and the command checks the SSPOS1 token like a till.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test']);
    $this->sent = [];

    Http::fake(function (ClientRequest $request) {
        expect($request->url())->toStartWith('https://portal.test/api/v1/');
        $this->sent[] = $request;

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

test('activate: the till gets a token, checks it and trades; the key is only shown masked', function () {
    [, $licence] = $this->keyedTenant();

    $this->artisan('licence:simulate', ['action' => 'activate', '--key' => self::KEY, '--install' => self::INSTALL, '--install-code' => self::INSTALL_CODE])
        ->expectsOutputToContain('SSP-••••-••••-••••-P8T5')
        ->expectsOutputToContain('Till: TRADE WITH BANNER.')
        ->doesntExpectOutputToContain(self::KEY)
        ->assertSuccessful();

    expect($licence->fresh()->device_id)->toBe(self::INSTALL)
        ->and($this->sent[0]->header('X-SSPOS-Contract')[0])->toBe('1')
        ->and($this->sent[0]->header('Idempotency-Key')[0])->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($this->sent[0]['installCode'])->toBe(self::INSTALL_CODE);
});

test('validate: no new token while nothing changed; locks when suspended', function () {
    [, $licence] = $this->keyedTenant();
    $token = (string) $this->activateTill()->json('licenceToken');
    $options = ['action' => 'validate', '--licence' => $licence->id, '--token' => $token, '--install' => self::INSTALL, '--install-code' => self::INSTALL_CODE];

    $this->artisan('licence:simulate', $options)->expectsOutputToContain('No new token')->expectsOutputToContain('Till: TRADE WITH BANNER.')->assertSuccessful();

    app(SuspendLicence::class)->handle($licence->fresh(), 'Check');
    $this->artisan('licence:simulate', $options)->expectsOutputToContain('Till: LOCK.')->assertSuccessful();
});

test('deactivate releases the key; a wrong key fails with the portal message', function () {
    [, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();

    $this->artisan('licence:simulate', ['action' => 'deactivate', '--register' => self::TILL_REGISTER, '--install' => self::INSTALL])
        ->expectsOutputToContain('"seat": "deactivated"')
        ->assertSuccessful();
    expect($licence->fresh()->device_id)->toBeNull();

    $this->artisan('licence:simulate', ['action' => 'activate', '--key' => self::OTHER_KEY, '--install' => self::OTHER_INSTALL])
        ->expectsOutputToContain('key.not_found')
        ->assertFailed();
});

test('an unknown action is refused', function () {
    $this->artisan('licence:simulate', ['action' => 'check-in'])->assertExitCode(2);
});
