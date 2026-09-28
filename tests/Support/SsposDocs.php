<?php

namespace Tests\Support;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use Carbon\CarbonImmutable;

/**
 * Contract v1.3.1 licensing samples and the documentation-only key pairs they are signed with.
 */
final class SsposDocs
{
    public const PORTAL_KID = 'k13799fa4';   // plays our portal key (the signer)

    public const APPROVER_KID = 'k76b44696'; // plays the owner's key generator (approver and local signer)

    public static function dir(): string
    {
        return base_path('docs/contracts/portal-api-v1.3.3/docs/web-portal-api/licensing');
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(string $file): array
    {
        return json_decode((string) file_get_contents(self::dir().'/samples/'.$file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{kid: string, seed: string, public: string, secret: string}
     */
    public static function key(string $kid): array
    {
        $key = collect(self::sample('licence-token.worked-example.json')['keys'])->firstWhere('kid', $kid);
        $pair = sodium_crypto_sign_seed_keypair(Base64Url::decode($key['privateSeedBase64Url']));

        return [
            'kid' => $kid,
            'seed' => $key['privateSeedBase64Url'],
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /** Stores a documentation key as our active signing key (as if generated here). */
    public static function storeActive(string $kid = self::PORTAL_KID, ?string $signerCert = null): LicenceSigningKey
    {
        $key = self::key($kid);
        $row = new LicenceSigningKey;
        $row->forceFill([
            'kid' => $kid,
            'public_key' => Base64Url::encode($key['public']),
            'secret_key' => Base64Url::encode($key['secret']),
            'signer_cert' => $signerCert,
            'is_active' => true,
        ])->save();

        return $row;
    }

    /** Trust both documentation keys the way production trusts the owner's (tests only). */
    public static function trust(): void
    {
        config([
            'licence.approvers' => config('licence.documentation_test_approvers'),
            'licence.trusted_keys' => [
                self::PORTAL_KID => Base64Url::encode(self::key(self::PORTAL_KID)['public']),
                self::APPROVER_KID => Base64Url::encode(self::key(self::APPROVER_KID)['public']),
            ],
        ]);
    }

    /**
     * A certificate signed by the documentation approver.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function certificate(string $publicKey, array $overrides = [], ?string $approverKid = self::APPROVER_KID): string
    {
        $payload = [
            'v' => 1,
            'kid' => 'k'.substr(hash('sha256', $publicKey), 0, 8),
            'publicKey' => Base64Url::encode($publicKey),
            'name' => 'SSPOS Portal',
            'issuedAt' => '2026-10-01T00:00:00Z',
            'approvedBy' => self::APPROVER_KID,
            ...$overrides,
        ];

        return SsposCodec::sign(SsposCodec::CERT_PREFIX, $payload, self::key($approverKid ?? self::APPROVER_KID)['secret']);
    }

    /**
     * LicenceClaims from a sample payload (portal samples only).
     *
     * @param  array<string, mixed>  $p
     */
    public static function claims(array $p): LicenceClaims
    {
        return new LicenceClaims(
            licenceId: $p['licenceId'],
            kind: TokenKind::from($p['kind']),
            companyId: $p['companyId'],
            branchId: $p['branchId'],
            businessName: $p['businessName'],
            branchName: $p['branchName'],
            maxRegisters: $p['maxRegisters'],
            issuedAt: CarbonImmutable::parse($p['issuedAt']),
            validFrom: CarbonImmutable::parse($p['validFrom']),
            expiresAt: CarbonImmutable::parse($p['expiresAt']),
            installCode: $p['installCode'] ?? null,
            features: $p['features'] ?? [],
            limits: $p['limits'] ?? [],
            notes: $p['notes'] ?? null,
            company: $p['company'] ?? [],
            issuer: $p['issuer'],
            onlineCheck: $p['onlineCheck'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public static function payloadOf(string $token): array
    {
        return json_decode(Base64Url::decode(explode('.', $token)[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
