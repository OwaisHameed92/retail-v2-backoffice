<?php

namespace Tests\Feature\Licensing\Api;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\LicenceTokenVerifier;
use App\Domain\Licensing\Signing\VerifiedToken;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for the module 1.5 licence API tests. Use with
 * `uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class)`.
 */
trait LicenceApiHelpers
{
    /** Test vector from docs/specs/licence-api-v1.md. */
    public const KEY = 'SSP-7K2Q-9DMF-3XRA-P8T5';

    public const OTHER_KEY = 'SSP-4HWC-J6ZB-81ME-QV5H';

    public const PC = 'PC-7F3A9C21-FRONT';

    public const OTHER_PC = 'PC-B81D44E0-BACK';

    public function withSigningKey(): void
    {
        app(GenerateSigningKey::class)->handle(force: true);
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
    public function tillHeaders(string $contract = '1'): array
    {
        return ['X-SSPOS-Licence-Contract' => $contract, 'X-SSPOS-App-Version' => '1.4.2'];
    }

    /**
     * @return array<string, mixed>
     */
    public function activateBody(string $key = self::KEY, string $device = self::PC, string $name = 'FRONT-TILL'): array
    {
        return [
            'licenceKey' => $key,
            'deviceId' => $device,
            'deviceName' => $name,
            'appVersion' => '1.4.2',
            'os' => 'Windows 11 Pro 23H2',
            'requestedAt' => '2026-09-24T09:00:00Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkInBody(string $key = self::KEY, string $device = self::PC, ?string $tokenId = null): array
    {
        return [
            'licenceKey' => $key,
            'deviceId' => $device,
            'appVersion' => '1.4.2',
            'tokenId' => $tokenId,
            'lastSaleAt' => '2026-09-24T08:55:10Z',
            'requestedAt' => '2026-09-24T09:00:00Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>|null  $headers
     */
    public function till(string $action, array $body, ?array $headers = null): TestResponse
    {
        return $this->postJson("/api/v1/licence/{$action}", $body, $headers ?? $this->tillHeaders());
    }

    public function verifyToken(TestResponse $response): VerifiedToken
    {
        return app(LicenceTokenVerifier::class)->verify((string) $response->json('token'));
    }
}
