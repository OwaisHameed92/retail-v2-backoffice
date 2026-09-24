<?php

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Ed25519Jws;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;

// RFC 8037 appendix A.1 / A.4: the published Ed25519 test key and JWS. Matching byte for byte proves our
// output is what any conforming verifier (NSec, BouncyCastle, jose libraries) expects.
const RFC8037_D = 'nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A';
const RFC8037_X = '11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo';
const RFC8037_JWS = 'eyJhbGciOiJFZERTQSJ9.RXhhbXBsZSBvZiBFZDI1NTE5IHNpZ25pbmc'
    .'.hgyY0il_MGCjP0JzlnLWG1PPOt7-09PGcvMg3AIbQR6dWbhijcNR4ki4iylGjg5BhVsPt9g7sVvpAr_MuM0KAg';

it('derives the RFC 8037 public key from its seed', function () {
    $pair = Ed25519Jws::keyPairFromSeed(Base64Url::decode(RFC8037_D));

    expect(Base64Url::encode($pair['public']))->toBe(RFC8037_X);
});

it('reproduces the RFC 8037 appendix A.4 signature exactly', function () {
    $pair = Ed25519Jws::keyPairFromSeed(Base64Url::decode(RFC8037_D));

    expect(Ed25519Jws::sign(['alg' => 'EdDSA'], 'Example of Ed25519 signing', $pair['secret']))->toBe(RFC8037_JWS);
});

it('verifies the RFC 8037 appendix A.5 JWS with the public key only', function () {
    $jws = Ed25519Jws::parse(RFC8037_JWS);

    expect($jws['header'])->toBe(['alg' => 'EdDSA'])
        ->and($jws['payload'])->toBe('Example of Ed25519 signing')
        ->and(Ed25519Jws::verify($jws['signingInput'], $jws['signature'], Base64Url::decode(RFC8037_X)))->toBeTrue();
});

it('rejects a flipped bit in any segment', function (int $segment) {
    $parts = explode('.', RFC8037_JWS);
    $bytes = Base64Url::decode($parts[$segment]);
    $bytes[0] = chr(ord($bytes[0]) ^ 0x01);
    $parts[$segment] = Base64Url::encode($bytes);

    try {
        $jws = Ed25519Jws::parse(implode('.', $parts));
    } catch (MalformedToken) {
        expect(true)->toBeTrue(); // a broken header is also a rejection

        return;
    }

    expect(Ed25519Jws::verify($jws['signingInput'], $jws['signature'], Base64Url::decode(RFC8037_X)))->toBeFalse();
})->with([0, 1, 2]);

it('rejects malformed compact tokens', function (string $token) {
    Ed25519Jws::parse($token);
})->throws(MalformedToken::class)->with([
    'empty' => '',
    'two segments' => 'eyJhbGciOiJFZERTQSJ9.RXhhbXBsZQ',
    'four segments' => RFC8037_JWS.'.AAAA',
    'empty payload' => 'eyJhbGciOiJFZERTQSJ9..'.str_repeat('A', 86),
    'padding' => 'eyJhbGciOiJFZERTQSJ9=.RXhhbXBsZQ.'.str_repeat('A', 86),
    'standard base64 chars' => 'eyJhbGciOiJFZERTQSJ9.RXhh+/Bs.'.str_repeat('A', 86),
    'header not json' => Base64Url::encode('nope').'.RXhhbXBsZQ.'.str_repeat('A', 86),
    'header a json list' => Base64Url::encode('["EdDSA"]').'.RXhhbXBsZQ.'.str_repeat('A', 86),
    'short signature' => 'eyJhbGciOiJFZERTQSJ9.RXhhbXBsZQ.'.str_repeat('A', 40),
    'too long' => str_repeat('A', Ed25519Jws::MAX_TOKEN_BYTES + 1),
]);

it('rejects non-canonical base64url with stray trailing bits', function () {
    // "RXhhbXBsZR" decodes to the same bytes as "RXhhbXBsZQ" but is not its canonical encoding.
    Base64Url::decode('RXhhbXBsZR');
})->throws(MalformedToken::class);

it('round-trips arbitrary bytes through base64url without padding', function () {
    foreach ([0, 1, 2, 3, 31, 32, 64] as $length) {
        $bytes = $length === 0 ? '' : random_bytes($length);
        $encoded = Base64Url::encode($bytes);

        expect($encoded)->not->toContain('=')->not->toContain('+')->not->toContain('/')
            ->and(Base64Url::decode($encoded))->toBe($bytes);
    }
});
