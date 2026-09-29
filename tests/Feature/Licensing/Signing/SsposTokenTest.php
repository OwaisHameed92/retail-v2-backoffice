<?php

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\InvalidSignature;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Domain\Licensing\Signing\Exceptions\UncertifiedSigningKey;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use App\Domain\Licensing\Signing\Exceptions\UnsupportedVersion;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Kid;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Licensing\Signing\Sspos\SsposTokenSigner;
use App\Domain\Licensing\Signing\Sspos\SsposTokenVerifier;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use Illuminate\Support\Facades\Log;
use Tests\Support\ContractSchema;
use Tests\Support\SsposDocs;

/** Re-signs a payload with a documentation key (for tampering tests). */
function ssposResign(array $payload, string $kid = SsposDocs::PORTAL_KID): string
{
    return SsposCodec::sign(SsposCodec::TOKEN_PREFIX, $payload, SsposDocs::key($kid)['secret']);
}

it('reproduces the contract worked example byte for byte', function () {
    $example = SsposDocs::sample('licence-token.worked-example.json');
    SsposDocs::storeActive();

    $token = app(SsposTokenSigner::class)->sign(SsposDocs::claims(SsposDocs::sample('licence-token.payload.trial.json')));

    expect(explode('.', $token)[1])->toBe($example['step2_payloadPart'])
        ->and($token)->toBe($example['step5_token'])
        ->and(hash('sha256', $token))->toBe($example['tokenSha256'])
        ->and(app(SsposTokenVerifier::class)->verify($token)->sha256())->toBe($example['tokenSha256']);
});

it('reproduces the per-till and migrated sample tokens byte for byte', function () {
    $other = SsposDocs::sample('licence-token.worked-example.json')['otherTokens'];
    SsposDocs::storeActive();
    $signer = app(SsposTokenSigner::class);

    expect($signer->sign(SsposDocs::claims(SsposDocs::sample('licence-token.payload.per-till.json'))))->toBe($other['perTill'])
        ->and($signer->sign(SsposDocs::claims(SsposDocs::payloadOf($other['migrated']))))->toBe($other['migrated']);
});

it('verifies every sample token signed with a documentation key', function () {
    SsposDocs::trust();
    $verifier = app(SsposTokenVerifier::class);
    $count = 0;

    foreach (glob(SsposDocs::dir().'/samples/*.json') as $file) {
        preg_match_all('/SSPOS1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', (string) file_get_contents($file), $matches);

        foreach (array_unique($matches[0]) as $token) {
            $verified = $verifier->verify($token);
            expect($verified->kid())->toBeIn([SsposDocs::PORTAL_KID, SsposDocs::APPROVER_KID]);
            $count++;
        }
    }

    expect($count)->toBeGreaterThanOrEqual(8);
});

it('derives kids from the SHA-256 of the public key', function () {
    expect(Kid::for(SsposDocs::key('k13799fa4')['public']))->toBe('k13799fa4')
        ->and(Kid::for(SsposDocs::key('k76b44696')['public']))->toBe('k76b44696');

    $key = app(GenerateSigningKey::class)->handle();

    expect($key->kid)->toMatch(Kid::PATTERN)->and($key->kid)->toBe(Kid::for($key->publicKey));
});

it('refuses a tampered payload, signature or prefix', function () {
    SsposDocs::storeActive();
    $token = SsposDocs::sample('licence-token.worked-example.json')['step5_token'];
    [$prefix, $part, $sig] = explode('.', $token);
    $verifier = app(SsposTokenVerifier::class);

    $payload = SsposDocs::payloadOf($token);
    $forgedPart = Base64Url::encode(json_encode([...$payload, 'maxRegisters' => 99], SsposCodec::JSON_FLAGS));
    $flipped = Base64Url::encode(substr_replace(Base64Url::decode($sig), chr(ord(Base64Url::decode($sig)[0]) ^ 1), 0, 1));

    expect(fn () => $verifier->verify("{$prefix}.{$forgedPart}.{$sig}"))->toThrow(InvalidSignature::class)
        ->and(fn () => $verifier->verify("{$prefix}.{$part}.{$flipped}"))->toThrow(InvalidSignature::class)
        ->and(fn () => $verifier->verify("SSPOS2.{$part}.{$sig}"))->toThrow(MalformedToken::class)
        ->and(fn () => $verifier->verify("{$prefix}.{$part}"))->toThrow(MalformedToken::class)
        ->and($verifier->verify(chunk_split($token, 60, "\r\n"))->token)->toBe($token);
});

it('refuses an unknown kid and a newer or missing version', function () {
    SsposDocs::storeActive();
    $payload = SsposDocs::sample('licence-token.payload.trial.json');
    $verifier = app(SsposTokenVerifier::class);

    expect(fn () => $verifier->verify(ssposResign([...$payload, 'kid' => 'k00000000'])))->toThrow(UnknownKey::class)
        ->and(fn () => $verifier->verify(ssposResign($payload, SsposDocs::APPROVER_KID)))->toThrow(InvalidSignature::class)
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'v' => 2])))->toThrow(UnsupportedVersion::class)
        ->and(fn () => $verifier->verify(ssposResign(array_diff_key($payload, ['v' => 1]))))->toThrow(MalformedToken::class);
});

it('checks the signer certificate a token carries', function () {
    SsposDocs::trust();
    $portal = SsposDocs::key(SsposDocs::PORTAL_KID);
    $payload = SsposDocs::sample('licence-token.payload.trial.json');
    $verifier = app(SsposTokenVerifier::class);
    $good = SsposDocs::certificate($portal['public']);

    [$certPrefix, $certPart, $certSig] = explode('.', $good);
    $tamperedCert = $certPrefix.'.'.Base64Url::encode(str_replace('SSPOS Portal', 'Evil Portal', Base64Url::decode($certPart))).'.'.$certSig;
    $otherKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

    expect($verifier->verify(ssposResign([...$payload, 'signerCert' => $good]))->signerCertificate?->approvedBy)->toBe(SsposDocs::APPROVER_KID)
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => $tamperedCert])))->toThrow(BadSignerCertificate::class)
        // a certificate for another key (kid mismatch with the token)
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => SsposDocs::certificate($otherKey)])))->toThrow(BadSignerCertificate::class)
        // right kid, wrong public key
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => SsposDocs::certificate($otherKey, ['kid' => SsposDocs::PORTAL_KID])])))->toThrow(BadSignerCertificate::class)
        // ended before the token was issued
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => SsposDocs::certificate($portal['public'], ['expiresAt' => '2026-09-01T00:00:00Z'])])))->toThrow(BadSignerCertificate::class)
        // not signed by an approver
        ->and(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => SsposDocs::certificate($portal['public'], [], SsposDocs::PORTAL_KID)])))->toThrow(BadSignerCertificate::class);

    config(['licence.approvers' => []]);
    expect(fn () => $verifier->verify(ssposResign([...$payload, 'signerCert' => $good])))->toThrow(BadSignerCertificate::class);
});

it('puts the active key signer certificate in every token', function () {
    SsposDocs::trust();
    $cert = SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public']);
    SsposDocs::storeActive(signerCert: $cert);

    $token = app(SsposTokenSigner::class)->sign(SsposDocs::claims(SsposDocs::sample('licence-token.payload.per-till.json')));

    expect(SsposDocs::payloadOf($token)['signerCert'])->toBe($cert)
        ->and(array_key_last(SsposDocs::payloadOf($token)))->toBe('signerCert')
        ->and(app(SsposTokenVerifier::class)->verify($token)->signerCertificate?->kid)->toBe(SsposDocs::PORTAL_KID);
});

it('blocks uncertified signing unless allowed, and warns when allowed', function () {
    SsposDocs::storeActive();
    $claims = SsposDocs::claims(SsposDocs::sample('licence-token.payload.trial.json'));

    config(['licence.allow_uncertified' => false]);
    expect(fn () => app(SsposTokenSigner::class)->sign($claims))->toThrow(UncertifiedSigningKey::class);

    config(['licence.allow_uncertified' => true]);
    Log::spy();
    app(SsposTokenSigner::class)->sign($claims);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context === ['kid' => SsposDocs::PORTAL_KID]);
});

it('omits empty optional fields and writes UTC dates with Z', function () {
    SsposDocs::storeActive();
    $claims = SsposDocs::claims([
        ...SsposDocs::sample('licence-token.payload.trial.json'),
        'installCode' => '', 'features' => [], 'limits' => [], 'notes' => '',
        'company' => ['town' => 'Leeds', 'email' => '', 'vatNumber' => null],
        'issuedAt' => '2026-09-26T10:00:00+01:00',
    ]);

    $payload = SsposDocs::payloadOf(app(SsposTokenSigner::class)->sign($claims));

    expect($payload)->not->toHaveKeys(['installCode', 'features', 'limits', 'notes', 'signerCert'])
        ->and($payload['company'])->toBe(['town' => 'Leeds'])
        ->and($payload['issuedAt'])->toBe('2026-09-26T09:00:00Z')
        ->and($payload['source'])->toBe('portal')
        ->and($payload['onlineCheck'])->toBe(['required' => true, 'intervalHours' => 24, 'graceDays' => 14]);
});

it('produces payloads that validate against licence-token-payload.schema.json', function () {
    $errors = fn (mixed $json) => ContractSchema::errors($json, 'licensing/schemas/licence-token-payload.schema.json');
    SsposDocs::storeActive(signerCert: SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public']));
    $signer = app(SsposTokenSigner::class);

    foreach (['trial', 'full', 'per-till'] as $sample) {
        $token = $signer->sign(SsposDocs::claims(SsposDocs::sample("licence-token.payload.{$sample}.json")));
        $json = json_decode(Base64Url::decode(explode('.', $token)[1]));

        expect($errors($json))->toBe([]);
    }

    foreach (['local', 'local-open'] as $sample) {
        $json = json_decode((string) file_get_contents(SsposDocs::dir()."/samples/licence-token.payload.{$sample}.json"));
        expect($errors($json))->toBe([]);
    }

    // The validator really checks: a decimal limit and a portal token without companyId are refused.
    $portal = ['v' => 1, 'kid' => 'k1', 'licenceId' => '01K5T0Q8C4000000000000Y001', 'kind' => 'full', 'source' => 'portal', 'issuer' => 'x', 'businessName' => 'b', 'branchName' => 'c', 'maxRegisters' => 1, 'issuedAt' => '2026-09-26T09:00:00Z', 'validFrom' => '2026-09-26T09:00:00Z', 'expiresAt' => '2026-10-03T09:00:00Z', 'companyId' => '01K5T0Q8C4000000000000C001', 'branchId' => '01K5T0Q8C4000000000000B001'];
    expect($errors($portal))->toBe([])
        ->and(implode(' | ', $errors([...$portal, 'limits' => ['users' => 1.5]])))->toContain('$.limits.users:', 'integer')
        ->and(implode(' | ', $errors(array_diff_key($portal, ['companyId' => 1]))))->toContain('companyId');
});

it('rejects invalid claims', function (array $change) {
    SsposDocs::claims([...SsposDocs::sample('licence-token.payload.per-till.json'), ...$change]);
})->throws(InvalidArgumentException::class)->with([
    'lower-case ulid' => [['licenceId' => '01k5t0q8c4000000000000y101']],
    'install code' => [['installCode' => 'AC4F3FHG']],
    'feature name' => [['features' => ['Multi-Branch']]],
    'duplicate feature' => [['features' => ['loyalty', 'loyalty']]],
    'decimal limit' => [['limits' => ['branches' => 1.5]]],
    'notes too long' => [['notes' => str_repeat('x', 201)]],
    'unknown company field' => [['company' => ['pin' => '1234']]],
    'no registers' => [['maxRegisters' => 0]],
    'expires before start' => [['expiresAt' => '2026-09-01T00:00:00Z']],
]);

it('exposes the verified fields', function () {
    SsposDocs::trust();
    $token = SsposDocs::sample('licence-token.worked-example.json')['otherTokens']['perTill'];

    $verified = app(SsposTokenVerifier::class)->verify($token);

    expect($verified->licenceId())->toBe('01K5T0Q8C4000000000000Y101')
        ->and($verified->kind())->toBe(TokenKind::Full)
        ->and($verified->source())->toBe('portal')
        ->and($verified->get('company.town'))->toBe('Leeds')
        ->and($verified->date('expiresAt')?->toIso8601ZuluString())->toBe('2027-09-27T23:59:59Z')
        ->and($verified->signerCertificate)->toBeNull()
        ->and(app(KeyStore::class)->hasActive())->toBeFalse();
});
