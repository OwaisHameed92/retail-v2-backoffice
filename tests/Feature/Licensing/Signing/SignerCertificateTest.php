<?php

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Licensing\Signing\Sspos\SignerCertificate;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Tests\Support\SsposDocs;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    config(['licence.approvers' => config('licence.documentation_test_approvers')]);
});

it('verifies the contract signer-certificate worked example', function () {
    $example = SsposDocs::sample('signer-certificate.worked-example.json');
    $approver = SsposDocs::key(SsposDocs::APPROVER_KID);

    $cert = SignerCertificate::parse($example['step5_certificate'])->check(config('licence.approvers'));

    expect($cert->kid)->toBe(SsposDocs::PORTAL_KID)
        ->and($cert->publicKey)->toBe(SsposDocs::key(SsposDocs::PORTAL_KID)['public'])
        ->and($cert->name)->toBe('SSPOS Portal')
        ->and($cert->approvedBy)->toBe(SsposDocs::APPROVER_KID)
        ->and($cert->expiresAt)->toBeNull()
        ->and(SsposCodec::sign(SsposCodec::CERT_PREFIX, json_decode($example['step1_certificatePayloadJson'], true), $approver['secret']))
        ->toBe($example['step5_certificate']);
});

it('ships the documentation approver only as a test entry', function () {
    expect(config('licence.documentation_test_approvers'))->toHaveKey(SsposDocs::APPROVER_KID)
        ->and(file_get_contents(config_path('licence.php')))->toContain("'approvers' => \$keyList(env('LICENCE_APPROVERS'))");
});

it('imports a valid certificate for the active key and audits it', function () {
    SsposDocs::storeActive();
    $cert = SsposDocs::sample('signer-certificate.worked-example.json')['step5_certificate'];

    $this->artisan('licence:keys:import-cert', ['cert' => chunk_split($cert, 80, "\n")])
        ->expectsOutputToContain('Signer certificate imported for k13799fa4')
        ->assertSuccessful();

    expect(app(KeyStore::class)->active()->signerCert)->toBe($cert)
        ->and(AuditLog::query()->where('action', 'licence_signing_key.certified')->value('meta'))
        ->toMatchArray(['kid' => SsposDocs::PORTAL_KID, 'approvedBy' => SsposDocs::APPROVER_KID]);

    $this->artisan('licence:keys:list')->expectsOutputToContain('yes')->assertSuccessful();
});

it('refuses certificates that are malformed, unapproved, for another key or ended', function (Closure $cert, string $message) {
    SsposDocs::storeActive();

    $this->artisan('licence:keys:import-cert', ['cert' => $cert()])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(LicenceSigningKey::query()->value('signer_cert'))->toBeNull();
})->with([
    'not a certificate' => [fn () => 'SSPOS1.abc.def', 'Malformed signer certificate'],
    'bad approver signature' => [fn () => SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public'], ['approvedBy' => SsposDocs::APPROVER_KID], SsposDocs::PORTAL_KID), 'signature does not verify'],
    'unknown approver' => [fn () => SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public'], ['approvedBy' => 'k00000000']), 'not from a configured approver'],
    'other kid' => [fn () => SsposDocs::certificate(SsposDocs::key(SsposDocs::APPROVER_KID)['public']), 'the active key is k13799fa4'],
    'kid of another public key' => [fn () => SsposDocs::certificate(SsposDocs::key(SsposDocs::APPROVER_KID)['public'], ['kid' => SsposDocs::PORTAL_KID]), 'kid does not match its public key'],
    'ended' => [fn () => SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public'], ['expiresAt' => '2026-09-30T00:00:00Z']), 'already ended'],
]);

it('refuses a certificate when no approver is configured', function () {
    config(['licence.approvers' => []]);
    SsposDocs::storeActive();

    expect(fn () => SignerCertificate::parse(SsposDocs::sample('signer-certificate.worked-example.json')['step5_certificate'])->check([]))
        ->toThrow(BadSignerCertificate::class);

    $this->artisan('licence:keys:import-cert', ['cert' => SsposDocs::sample('signer-certificate.worked-example.json')['step5_certificate']])
        ->assertFailed();
});

it('prints the public-key hand-over exactly shaped like the contract sample, without secrets', function () {
    config(['licence.handover_contact' => 'ops@example.test']);
    $key = app(GenerateSigningKey::class)->handle();
    $secret = app(KeyStore::class)->active()->secretKey();
    $path = storage_path('framework/testing/handover-'.uniqid().'.json');
    File::ensureDirectoryExists(dirname($path));

    $this->artisan('licence:keys:handover', ['--path' => $path])->assertSuccessful();

    $json = (string) file_get_contents($path);
    $handover = json_decode($json, true);
    File::delete($path);

    $pem = $handover['publicKeyPem'];
    $der = base64_decode(trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $pem)));

    expect(array_keys($handover))->toBe(array_keys(SsposDocs::sample('public-key-handover.json')))
        ->and($handover['kid'])->toBe($key->kid)
        ->and($handover['alg'])->toBe('Ed25519')
        ->and($handover['publicKeyBase64Url'])->toBe($key->x())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($pem)->toStartWith("-----BEGIN PUBLIC KEY-----\n")
        ->and(bin2hex(substr($der, 0, 12)))->toBe('302a300506032b6570032100')
        ->and(substr($der, 12))->toBe($key->publicKey)
        ->and($handover['notBefore'])->toBe('2026-10-01T12:00:00Z')
        ->and($handover['contact'])->toBe('ops@example.test');

    foreach ([Base64Url::encode($secret), base64_encode($secret), bin2hex($secret), Base64Url::encode(substr($secret, 0, 32))] as $spelling) {
        expect(str_contains($json, $spelling))->toBeFalse();
    }
});

it('fails the hand-over when there is no key', function () {
    $this->artisan('licence:keys:handover')->expectsOutputToContain('licence:keys:generate')->assertFailed();
});

it('keeps the secret out of serialisation when a certificate is stored', function () {
    SsposDocs::storeActive(signerCert: SsposDocs::sample('signer-certificate.worked-example.json')['step5_certificate']);
    $key = app(KeyStore::class)->active();
    $seed = SsposDocs::key(SsposDocs::PORTAL_KID)['seed'];

    expect(json_encode($key))->not->toContain($seed)
        ->and(json_encode(LicenceSigningKey::query()->first()))->not->toContain($seed)
        ->and(print_r($key, true))->not->toContain($seed)
        ->and(fn () => serialize($key))->toThrow(LogicException::class);
});
