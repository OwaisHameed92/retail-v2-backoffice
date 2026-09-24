<?php

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Actions\RotateSigningKey;
use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\LicenceTokenException;
use App\Domain\Licensing\Signing\Jwks;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\LicenceTokenSigner;
use App\Domain\Licensing\Signing\LicenceTokenVerifier;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Every spelling of the active secret key a leak could take.
 *
 * @return list<string>
 */
function licenceSecretSpellings(): array
{
    $secret = app(KeyStore::class)->active()->secretKey();

    return [
        Base64Url::encode($secret),
        base64_encode($secret),
        bin2hex($secret),
        Base64Url::encode(substr($secret, 0, 32)), // the seed alone is the whole private key
        bin2hex(substr($secret, 0, 32)),
    ];
}

function licenceExpectNoSecretIn(string $haystack, array $spellings): void
{
    foreach ($spellings as $spelling) {
        expect(str_contains($haystack, $spelling))->toBeFalse();
    }
}

beforeEach(function () {
    app(GenerateSigningKey::class)->handle();
    $this->secrets = licenceSecretSpellings();
});

it('keeps the secret out of SigningKey toArray, JSON and debug dumps', function () {
    $key = app(KeyStore::class)->active();

    ob_start();
    var_dump($key);
    $dump = (string) ob_get_clean();

    expect(array_keys($key->toArray()))->toBe(['kid', 'publicKey', 'createdAt', 'retiredAt']);

    foreach ([json_encode($key), json_encode($key->toArray()), print_r($key, true), $dump] as $output) {
        licenceExpectNoSecretIn((string) $output, $this->secrets);
    }
});

it('refuses to serialize a SigningKey', function () {
    serialize(app(KeyStore::class)->active());
})->throws(LogicException::class);

it('keeps the secret out of the model toArray and JSON', function () {
    $row = LicenceSigningKey::query()->where('is_active', true)->firstOrFail();

    expect($row->toArray())->not->toHaveKey('secret_key');
    licenceExpectNoSecretIn($row->toJson(), $this->secrets);
});

it('stores the secret encrypted with APP_KEY', function () {
    $raw = (string) LicenceSigningKey::query()->where('is_active', true)->toBase()->value('secret_key');

    licenceExpectNoSecretIn($raw, $this->secrets);
    expect(Crypt::decryptString($raw))->toBe($this->secrets[0]);
});

it('exposes only public keys in the JWKS', function () {
    licenceExpectNoSecretIn(json_encode(app(Jwks::class)->current()), $this->secrets);
});

it('never writes the secret to logs, audit rows or command output', function () {
    config(['logging.default' => 'null']); // capture via the event only; do not write laravel.log
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    Log::info('test marker');

    $token = app(LicenceTokenSigner::class)->sign(['lic' => 'x']);
    app(LicenceTokenVerifier::class)->verify($token);

    Artisan::call('licence:keys:list');
    $output = Artisan::output();
    Artisan::call('licence:keys:generate');
    $output .= Artisan::output();

    $messages = [];
    try {
        app(LicenceTokenVerifier::class)->verify($token.'x');
    } catch (LicenceTokenException $e) {
        $messages[] = $e->getMessage();
        report($e);
    }

    $before = $this->secrets;
    app(RotateSigningKey::class)->handle();
    Artisan::call('licence:keys:rotate');
    $output .= Artisan::output();

    $haystack = implode("\n", [...$logged, $output, ...$messages, AuditLog::query()->get()->toJson()]);

    expect($logged)->not->toBeEmpty();
    licenceExpectNoSecretIn($haystack, [...$before, ...licenceSecretSpellings()]);
    // Exception messages never echo the token either.
    expect(implode(' ', $messages))->not->toContain(explode('.', $token)[2]);
});
