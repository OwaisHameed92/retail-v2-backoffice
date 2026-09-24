<?php

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Ed25519Jws;
use App\Domain\Licensing\Signing\Exceptions\InvalidClaims;
use App\Domain\Licensing\Signing\Exceptions\InvalidSignature;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\LicenceTokenSigner;
use App\Domain\Licensing\Signing\LicenceTokenVerifier;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->key = app(GenerateSigningKey::class)->handle();
    $this->signer = app(LicenceTokenSigner::class);
    $this->verifier = app(LicenceTokenVerifier::class);
});

/** Signs with the active key but lets a test choose the exact header and payload. */
function licenceForgeWithActiveKey(array $header, array|string $payload): string
{
    $body = is_string($payload) ? $payload : json_encode($payload, Ed25519Jws::JSON_FLAGS);

    return Ed25519Jws::sign($header, $body, app(KeyStore::class)->active()->secretKey());
}

/** Replaces one segment of a compact token. */
function licenceReplaceSegment(string $token, int $index, string $value): string
{
    $parts = explode('.', $token);
    $parts[$index] = $value;

    return implode('.', $parts);
}

it('round-trips claims through sign and verify', function () {
    $claims = [
        'lic' => Ulid::new(),
        'keyLast4' => 'AB12',
        'status' => 'active',
        'features' => ['stock', 'loyalty'],
        'validUntil' => '2026-10-08T10:00:00Z',
        'note' => 'https://example.test/ä',
    ];

    $verified = $this->verifier->verify($this->signer->sign($claims));

    expect($verified->claims())->toMatchArray($claims)
        ->and($verified->kid())->toBe($this->key->kid)
        ->and($verified->header())->toBe(['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt']);
});

it('writes the exact header, unpadded base64url and unescaped slashes', function () {
    $token = $this->signer->sign(['url' => 'https://a/b']);
    [$header, $payload, $signature] = explode('.', $token);

    expect(Base64Url::decode($header))->toBe('{"alg":"EdDSA","kid":"'.$this->key->kid.'","typ":"sspos-licence+jwt"}')
        ->and(Base64Url::decode($payload))->toContain('"url":"https://a/b"')
        ->and($token)->not->toContain('=')
        ->and(strlen(Base64Url::decode($signature)))->toBe(64);
});

it('adds iss, iat and jti when absent', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));

    $verified = $this->verifier->verify($this->signer->sign(['lic' => 'x']));

    expect($verified->claim('iss'))->toBe('sspos-portal')
        ->and($verified->claim('iat'))->toBe(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC')->getTimestamp())
        ->and($verified->issuedAt()?->toIso8601ZuluString())->toBe('2026-09-24T12:00:00Z')
        ->and(Ulid::isValid($verified->jti()))->toBeTrue();
});

it('keeps iat and jti given by the caller', function () {
    $verified = $this->verifier->verify($this->signer->sign(['iat' => 1700000000, 'jti' => 'fixed-id', 'iss' => 'sspos-portal']));

    expect($verified->claim('iat'))->toBe(1700000000)->and($verified->jti())->toBe('fixed-id');
});

it('refuses to sign a foreign iss', function () {
    $this->signer->sign(['iss' => 'someone-else']);
})->throws(InvalidArgumentException::class);

it('refuses to sign without an active key', function () {
    LicenceSigningKey::query()->delete();

    $this->signer->sign(['lic' => 'x']);
})->throws(NoActiveSigningKey::class);

it('gives a fresh jti to every token', function () {
    $a = $this->verifier->verify($this->signer->sign([]))->jti();
    $b = $this->verifier->verify($this->signer->sign([]))->jti();

    expect($a)->not->toBe($b);
});

it('rejects a tampered payload', function () {
    $token = $this->signer->sign(['status' => 'suspended']);
    $forged = Base64Url::encode(str_replace('suspended', 'active', Base64Url::decode(explode('.', $token)[1])));

    $this->verifier->verify(licenceReplaceSegment($token, 1, $forged));
})->throws(InvalidSignature::class);

it('rejects a tampered header even when it stays valid', function () {
    $token = $this->signer->sign(['status' => 'active']);
    $header = Base64Url::encode(json_encode(['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt', 'x' => 1]));

    $this->verifier->verify(licenceReplaceSegment($token, 0, $header));
})->throws(InvalidSignature::class);

it('rejects a tampered signature', function () {
    $token = $this->signer->sign(['status' => 'active']);
    $signature = Base64Url::decode(explode('.', $token)[2]);
    $signature[10] = chr(ord($signature[10]) ^ 0x80);

    $this->verifier->verify(licenceReplaceSegment($token, 2, Base64Url::encode($signature)));
})->throws(InvalidSignature::class);

it('rejects a signature from another Ed25519 key with the same kid', function () {
    $other = Ed25519Jws::newKeyPair();
    $token = Ed25519Jws::sign(
        ['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt'],
        json_encode(['iss' => 'sspos-portal']),
        $other['secret'],
    );

    $this->verifier->verify($token);
})->throws(InvalidSignature::class);

it('rejects a wrong or missing alg, even when correctly signed', function (mixed $alg) {
    $header = ['kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt'];
    if ($alg !== null) {
        $header['alg'] = $alg;
    }

    $this->verifier->verify(licenceForgeWithActiveKey($header, ['iss' => 'sspos-portal']));
})->throws(MalformedToken::class)->with(['none', 'HS256', 'ES256', 'eddsa', null]);

it('rejects an unsigned "alg: none" token', function () {
    $token = Base64Url::encode('{"alg":"none","kid":"'.$this->key->kid.'","typ":"sspos-licence+jwt"}')
        .'.'.Base64Url::encode('{"iss":"sspos-portal"}').'.';

    $this->verifier->verify($token);
})->throws(MalformedToken::class);

it('rejects a wrong or missing typ', function (?string $typ) {
    $header = ['alg' => 'EdDSA', 'kid' => $this->key->kid];
    if ($typ !== null) {
        $header['typ'] = $typ;
    }

    $this->verifier->verify(licenceForgeWithActiveKey($header, ['iss' => 'sspos-portal']));
})->throws(MalformedToken::class)->with(['JWT', 'sspos-sync+jwt', null]);

it('rejects a crit header', function () {
    $header = ['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt', 'crit' => ['exp']];

    $this->verifier->verify(licenceForgeWithActiveKey($header, ['iss' => 'sspos-portal']));
})->throws(MalformedToken::class);

it('rejects a missing or non-string kid', function (mixed $kid) {
    $this->verifier->verify(licenceForgeWithActiveKey(['alg' => 'EdDSA', 'kid' => $kid, 'typ' => 'sspos-licence+jwt'], ['iss' => 'sspos-portal']));
})->throws(MalformedToken::class)->with([null, 5, '', 'x-very-long-kid-that-is-not-ours-at-all']);

it('rejects an unknown kid', function () {
    $this->verifier->verify(licenceForgeWithActiveKey(['alg' => 'EdDSA', 'kid' => 'lk1999-01', 'typ' => 'sspos-licence+jwt'], ['iss' => 'sspos-portal']));
})->throws(UnknownKey::class);

it('rejects a validly signed token with the wrong issuer', function () {
    $this->verifier->verify(licenceForgeWithActiveKey(
        ['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt'],
        ['iss' => 'evil-portal'],
    ));
})->throws(InvalidClaims::class);

it('rejects a validly signed payload that is not a JSON object', function (string $payload) {
    $this->verifier->verify(licenceForgeWithActiveKey(['alg' => 'EdDSA', 'kid' => $this->key->kid, 'typ' => 'sspos-licence+jwt'], $payload));
})->throws(MalformedToken::class)->with(['"text"', '[1,2]', 'not json', '42']);

it('rejects garbage input', function (string $token) {
    $this->verifier->verify($token);
})->throws(MalformedToken::class)->with(['', 'abc', 'a.b', 'a.b.c.d', 'Bearer x.y.z']);
