<?php

use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TillProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\PakPosContract;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Pak POS pack 2026-10-07, contract §17.18: the licence names its country. PK (`country-pk`): "PK" in the signed token
 * payload and at the top of licence/activate and licence/validate, every reply valid against the Pak POS contract's own
 * schemas (docs/contracts/pak-pos-2026-10-07), and `minimumAppVersion` on the Pak POS line. GB golden: no `country`
 * anywhere, the same reply and token members as before, `minimumAppVersion` 0.1.0.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
});

const PAK_ACTIVATE_MEMBERS = ['status', 'licenceToken', 'licence', 'companyId', 'branchId', 'portalTimeUtc', 'nextCheckAfterSeconds', 'messages'];
const PAK_VALIDATE_MEMBERS = ['status', 'licenceToken', 'licence', 'companyId', 'branchId', 'apiKey', 'minimumAppVersion', 'portalTimeUtc', 'nextCheckAfterSeconds', 'messages'];

test('GB golden: activate, validate and the token carry no country; members and minimumAppVersion as before', function () {
    $activate = $this->activateTill()->assertOk();
    $payload = (array) PakPosContract::tokenPayload($activate->json());

    expect(array_keys($activate->json()))->toBe(PAK_ACTIVATE_MEMBERS)
        ->and($payload)->not->toHaveKey('country')
        ->and(array_keys($payload))->toBe(['v', 'kid', 'licenceId', 'kind', 'source', 'issuer', 'companyId', 'branchId', 'businessName', 'branchName', 'installCode', 'maxRegisters', 'features', 'issuedAt', 'validFrom', 'expiresAt', 'onlineCheck', 'limits', 'company', 'signerCert'])
        ->and(TillProfile::licenceCountry())->toBeNull()
        ->and(TillProfile::countryMember())->toBe([]);

    $validate = $this->validateTill($this->licence->id, $activate->json('licenceToken'))->assertOk();
    expect(array_keys($validate->json()))->toBe(PAK_VALIDATE_MEMBERS)
        ->and($validate->json('minimumAppVersion'))->toBe('0.1.0')
        ->and($validate->json('licenceToken'))->toBeNull(); // nothing changed: the till keeps its token
});

test('PK: country "PK" in the signed token and at the top of activate and validate, valid against the Pak POS contract', function () {
    CountryModulesFixtures::pakistan();

    $activate = $this->activateTill()->assertOk();
    $payload = PakPosContract::tokenPayload($activate->json());

    expect($activate->json('country'))->toBe('PK')
        ->and($payload->country ?? null)->toBe('PK')
        ->and($this->verifyToken($activate)->payload['country'])->toBe('PK')
        ->and(array_keys($activate->json()))->toBe([...PAK_ACTIVATE_MEMBERS, 'country'])
        ->and(PakPosContract::errors($activate->json(), 'licensing/schemas/licence-activate-reply.schema.json'))->toBe([])
        ->and(PakPosContract::errors($payload, 'licensing/schemas/licence-token-payload.schema.json'))->toBe([]);

    $validate = $this->validateTill($this->licence->id, $activate->json('licenceToken'))->assertOk();
    expect($validate->json('country'))->toBe('PK')
        ->and($validate->json('minimumAppVersion'))->toBe('1.0.0')
        ->and(PakPosContract::errors($validate->json(), 'licensing/schemas/validate-reply.schema.json'))->toBe([]);
})->group('country-pk');

test('PK: a till holding a token made without a country gets a new one, with "PK", at its next check', function () {
    config(['country.code' => 'GB']); // the key was activated before the profile named a country (also under COUNTRY=PK)
    app()->forgetInstance(Country::class);
    $held = (string) $this->activateTill()->assertOk()->json('licenceToken');
    expect((array) PakPosContract::tokenPayload(['licenceToken' => $held]))->not->toHaveKey('country');

    CountryModulesFixtures::pakistan();
    $validate = $this->validateTill($this->licence->id, $held)->assertOk();

    expect($validate->json('licenceToken'))->toBeString()
        ->and(PakPosContract::tokenPayload($validate->json())->country ?? null)->toBe('PK')
        ->and($this->licence->fresh()->previous_token_sha256)->toBe(hash('sha256', $held))
        ->and(PakPosContract::errors(PakPosContract::tokenPayload($validate->json()), 'licensing/schemas/licence-token-payload.schema.json'))->toBe([]);
})->group('country-pk');

test('the licence country is a profile setting: "GB" can be switched on for the UK later; a bad value is never sent', function () {
    config(['country.profiles.GB.till.licenceCountry' => 'GB']);
    app()->forgetInstance(Country::class);
    $activate = $this->activateTill()->assertOk();
    expect($activate->json('country'))->toBe('GB')->and(PakPosContract::tokenPayload($activate->json())->country ?? null)->toBe('GB');

    config(['country.profiles.GB.till.licenceCountry' => 'uk!']);
    app()->forgetInstance(Country::class);
    expect(TillProfile::licenceCountry())->toBeNull()->and(TillProfile::countryMember())->toBe([]);
});

test('the PK signer certificate is imported as on the UK (licence:keys:import-cert, contract §17.17)', function () {
    CountryModulesFixtures::pakistan();
    LicenceSigningKey::query()->update(['signer_cert' => null]); // the PK key before the owner's certificate arrives
    $cert = SsposDocs::sample('signer-certificate.worked-example.json')['step5_certificate'];

    $this->artisan('licence:keys:import-cert', ['cert' => $cert])
        ->expectsOutputToContain('Signer certificate imported for '.SsposDocs::PORTAL_KID)
        ->assertSuccessful();

    $activate = $this->activateTill()->assertOk();
    expect(PakPosContract::tokenPayload($activate->json())->signerCert ?? null)->toBe($cert)
        ->and($activate->json('country'))->toBe('PK');
})->group('country-pk');

test('PK: the licence company.vatNumber is the STRN (vat_number), as the Pak POS till reads it; our own ids keep their names', function () {
    CountryModulesFixtures::pakistan();
    $this->company->forceFill(['vat_number' => '1700123456789', 'company_number' => '1234567-8', 'strn' => null])->saveQuietly();
    $this->licence->branch->forceFill(['vat_number' => null])->saveQuietly();

    $payload = PakPosContract::tokenPayload($this->activateTill()->assertOk()->json());

    expect($payload->company->vatNumber ?? null)->toBe('1700123456789')
        ->and(app(Country::class)->vatNumberPrefix())->toBe('STRN')
        ->and(app(Country::class)->sellerIdLabel('vatNumber'))->toBe('NTN')
        ->and(app(Country::class)->toFrontend()['sellerIds'])->toBe(['companyNumber' => 'SECP registration number', 'vatNumber' => 'NTN']);
})->group('country-pk');
