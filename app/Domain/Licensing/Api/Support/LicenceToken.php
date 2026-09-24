<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\LicenceTokenSigner;
use App\Domain\Licensing\Signing\ValidUntil;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Builds and signs the licence token of a reply (docs/specs/licence-api-v1.md, "Token").
 *
 * - `status` is the effective status (LicenceState), so a suspended company or till is in the token too.
 * - `expiresAt` is the paid expiry, or the trial end while on trial; `graceDays` the grace that applies now.
 * - `validUntil = min(iat + offline_days, expiresAt + graceDays)` (ValidUntil::compute). A licence with no end
 *   date (never activated) gets validUntil = iat: the till must not trade on it.
 */
final class LicenceToken
{
    public function __construct(
        private readonly LicenceTokenSigner $signer,
        private readonly Config $config,
    ) {}

    public function for(Licence $licence, LicenceState $state, CarbonImmutable $now): string
    {
        return $this->signer->sign($this->claims($licence, $state, $now));
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(Licence $licence, LicenceState $state, CarbonImmutable $now): array
    {
        $issuedAt = $now->utc()->startOfSecond();
        $endsAt = $state->endsAt;
        $graceDays = max(0, $licence->grace_days);

        $validUntil = $endsAt === null
            ? $issuedAt
            : ValidUntil::compute($issuedAt, (int) $this->config->get('licence.offline_days', 14), $endsAt, $graceDays);

        return [
            'iat' => $issuedAt->getTimestamp(),
            'jti' => Ulid::new(),
            'lic' => $licence->id,
            'keyLast4' => $licence->key_last4,
            'companyId' => $licence->company_id,
            'branchId' => $licence->branch_id,
            'registerId' => $licence->register_id,
            'deviceId' => $licence->device_id,
            'status' => $state->status->value,
            'plan' => $licence->plan?->code,
            'features' => self::features($licence),
            'expiresAt' => ApiDate::format($endsAt),
            'graceDays' => $graceDays,
            'validUntil' => ApiDate::format($validUntil),
        ];
    }

    /**
     * @return list<string>
     */
    public static function features(Licence $licence): array
    {
        return $licence->features->map(fn (Feature $feature) => $feature->value)->values()->all();
    }
}
