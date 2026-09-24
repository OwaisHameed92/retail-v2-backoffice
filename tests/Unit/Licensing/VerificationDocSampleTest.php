<?php

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Ed25519Jws;
use App\Domain\Licensing\Signing\ValidUntil;
use Carbon\CarbonImmutable;

// Keeps docs/specs/licence-token-verification.md honest: its TEST ONLY sample must verify with its JWKS.
it('verifies the sample token published in the EPOS verification guide', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/specs/licence-token-verification.md');

    expect(preg_match('/^(eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/m', $doc, $token))->toBe(1)
        ->and(preg_match('/"kid":"lk-test-01","kty":"OKP","crv":"Ed25519","x":"([A-Za-z0-9_-]{43})"/', $doc, $x))->toBe(1);

    $jws = Ed25519Jws::parse($token[1]);
    $claims = Ed25519Jws::decodeJsonObject($jws['payload'], 'payload');

    expect($jws['header'])->toBe(['alg' => 'EdDSA', 'kid' => 'lk-test-01', 'typ' => 'sspos-licence+jwt'])
        ->and(Ed25519Jws::verify($jws['signingInput'], $jws['signature'], Base64Url::decode($x[1])))->toBeTrue()
        ->and($claims['iss'])->toBe('sspos-portal')
        ->and(ValidUntil::compute(
            CarbonImmutable::createFromTimestampUTC($claims['iat']),
            14,
            CarbonImmutable::parse($claims['expiresAt']),
            $claims['graceDays'],
        )->toIso8601ZuluString())->toBe($claims['validUntil']);
});
