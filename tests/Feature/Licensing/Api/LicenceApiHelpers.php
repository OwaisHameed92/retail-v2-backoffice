<?php

namespace Tests\Feature\Licensing\Api;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\SsposTokenVerifier;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Testing\TestResponse;
use Tests\Support\JsonSchemaSubset;
use Tests\Support\SsposDocs;

/**
 * Helpers for the module 1.5 licence API tests (contract v1.3.1 §17.15). Use with
 * `uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class)`.
 */
trait LicenceApiHelpers
{
    /** Test vector from docs/specs/licence-key-format.md. */
    public const KEY = 'SSP-7K2Q-9DMF-3XRA-P8T5';

    public const OTHER_KEY = 'SSP-4HWC-J6ZB-81ME-QV5H';

    public const INSTALL = '01K5T0Q8C4000000000000J001';

    public const INSTALL_CODE = 'AC4F-3FHG';

    public const OTHER_INSTALL = '01K5T0Q8C4000000000000J002';

    public const OTHER_CODE = 'BK7Q-2MXD';

    /** The till's own ids (existingIds), as in the samples. */
    public const TILL_COMPANY = '01K5T0Q8C4000000000000C001';

    public const TILL_BRANCH = '01K5T0Q8C4000000000000B001';

    public const TILL_REGISTER = '01K5T0Q8C4000000000000R001';

    /** Module 2.1: another PC holds its own ids (never the same as INSTALL's, or id_map would see a conflict). */
    public const OTHER_TILL_COMPANY = '01K5T0Q8C4000000000000C002';

    public const OTHER_TILL_BRANCH = '01K5T0Q8C4000000000000B002';

    public const OTHER_TILL_REGISTER = '01K5T0Q8C4000000000000R002';

    /** Our signing key = the documentation portal key, certified by the documentation approver, both trusted. */
    public function withSigningKey(): void
    {
        SsposDocs::trust();
        SsposDocs::storeActive(SsposDocs::PORTAL_KID, SsposDocs::certificate(SsposDocs::key(SsposDocs::PORTAL_KID)['public']));
    }

    /** Gives a licence a known key: a real key's plain text exists only in the reply that created it. */
    public function giveKey(Licence $licence, string $plain = self::KEY): Licence
    {
        $key = LicenceKey::parse($plain);
        $licence->forceFill(['key_hash' => $key->hash(), 'key_last4' => $key->last4()])->save();

        return $licence->refresh();
    }

    /**
     * A licensed tenant (standard plan: 7-day trial, 3 trial grace days, 7 paid) whose first till has KEY.
     *
     * @return array{0: Company, 1: Licence}
     */
    public function keyedTenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS', string $key = self::KEY): array
    {
        $company = $this->licensedTenant($name, $tills, $code);

        return [$company, $this->giveKey($this->firstLicence($company, $code), $key)];
    }

    /**
     * @return array<string, string>
     */
    public function tillHeaders(string $install = self::INSTALL, ?string $idempotencyKey = null): array
    {
        return [
            'X-SSPOS-Contract' => '1',
            'X-SSPOS-App-Version' => '3.0.412',
            'X-SSPOS-Install-Id' => $install,
            'X-SSPOS-Company-Id' => self::TILL_COMPANY,
            'X-SSPOS-Branch-Id' => self::TILL_BRANCH,
            'X-SSPOS-Register-Id' => self::TILL_REGISTER,
            'Idempotency-Key' => $idempotencyKey ?? Ulid::new(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activateBody(string $key = self::KEY, string $install = self::INSTALL, string $code = self::INSTALL_CODE, string $name = 'TILL-1'): array
    {
        return [
            'licenceKey' => $key,
            'installId' => $install,
            'installCode' => $code,
            'deviceName' => $name,
            'appVersion' => '3.0.412',
            'os' => ['name' => 'Windows', 'version' => '10.0.26200', 'architecture' => 'x64'],
            'tillClockUtc' => now()->addSeconds(90)->utc()->format('Y-m-d\TH:i:s\Z'),
            'approverKids' => [SsposDocs::APPROVER_KID],
            'trustedKids' => [SsposDocs::PORTAL_KID, SsposDocs::APPROVER_KID],
            'existingIds' => $install === self::INSTALL
                ? ['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => self::TILL_REGISTER]
                : ['companyId' => self::OTHER_TILL_COMPANY, 'branchId' => self::OTHER_TILL_BRANCH, 'registerId' => self::OTHER_TILL_REGISTER],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function validateBody(string $licenceId, ?string $token, string $install = self::INSTALL, array $overrides = []): array
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return [
            'installId' => $install,
            'installCode' => $install === self::INSTALL ? self::INSTALL_CODE : self::OTHER_CODE,
            'licenceId' => $licenceId,
            'tokenSha256' => hash('sha256', (string) $token),
            'deviceName' => 'TILL-1',
            'appVersion' => '3.0.412',
            'os' => ['name' => 'Windows', 'version' => '10.0.26200', 'architecture' => 'x64'],
            'approverKids' => [SsposDocs::APPROVER_KID],
            'trustedKids' => [SsposDocs::PORTAL_KID, SsposDocs::APPROVER_KID],
            'tillClockUtc' => $now,
            'clockWatermarkUtc' => $now,
            'lastValidatedAtUtc' => null,
            'lock' => ['locked' => false, 'reason' => null],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>|null  $headers
     */
    public function till(string $path, array $body, ?array $headers = null): TestResponse
    {
        return $this->postJson("/api/v1/{$path}", $body, $headers ?? $this->tillHeaders((string) ($body['installId'] ?? self::INSTALL)));
    }

    public function activateTill(string $key = self::KEY, string $install = self::INSTALL, string $code = self::INSTALL_CODE): TestResponse
    {
        return $this->till('licence/activate', $this->activateBody($key, $install, $code));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function validateTill(string $licenceId, ?string $token, string $install = self::INSTALL, array $overrides = []): TestResponse
    {
        return $this->till('licence/validate', $this->validateBody($licenceId, $token, $install, $overrides));
    }

    public function deactivateTill(string $registerId = self::TILL_REGISTER, string $install = self::INSTALL): TestResponse
    {
        return $this->till('devices/deactivate', ['registerId' => $registerId, 'installId' => $install, 'reason' => 'removed', 'note' => null]);
    }

    public function verifyToken(TestResponse $response): VerifiedSsposToken
    {
        return app(SsposTokenVerifier::class)->verify((string) $response->json('licenceToken'));
    }

    public function schemas(): JsonSchemaSubset
    {
        return new JsonSchemaSubset(SsposDocs::dir().'/schemas');
    }

    /**
     * Errors of a reply against a licensing schema (empty when valid).
     *
     * @return list<string>
     */
    public function schemaErrors(TestResponse $response, string $schema): array
    {
        return $this->schemas()->validate(json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR), $schema);
    }
}
