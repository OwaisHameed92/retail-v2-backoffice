<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Licensing\Models\LocalLicenceKeyRefusal;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Models\IdMapping;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The local key register (contract v1.4.1 §17.6 (b) steps 2–4, §17.10, §17.16): `licenceId → installCode`.
 *
 * - First sighting of a licenceId → recorded with the token's fields, the reporting install and our business and
 *   shop when we can tell.
 * - The same licenceId from the same install code (a retry, a renewal for that till, a reinstall) → recorded again;
 *   the newer token's hash and dates are kept.
 * - The same licenceId from another install code → 409 `key.used_on_another_install` (the first PC keeps trading);
 *   the refusal is kept for support. Nothing about the first record changes.
 */
final class LocalKeyRegister
{
    /**
     * @param  string  $via  report (a local till's report), redeem (a linked till), migrate (cloud/migrate)
     *
     * @throws ApiException key.used_on_another_install, request.invalid
     */
    public function record(VerifiedSsposToken $token, TillRequest $till, ?string $companyId, ?string $branchId, string $via): LocalLicenceKey
    {
        $installCode = $till->installCode ?? throw LicenceApiErrors::invalid('The install code is missing.', 'installCode');
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        try {
            [$key, $refused] = $this->write($token, $till, $installCode, $companyId, $branchId, $via, $now);
        } catch (UniqueConstraintViolationException) {
            [$key, $refused] = $this->write($token, $till, $installCode, $companyId, $branchId, $via, $now); // two first sightings at once
        }

        if ($refused) {
            throw RedeemErrors::keyUsedOnAnotherInstall($key);
        }

        return $key;
    }

    /**
     * Our business and shop for a till's own ids: its branch id, else its company id, through id_map.
     *
     * @return array{0: string|null, 1: string|null} [company, branch]
     */
    public static function resolve(?string $tillCompanyId, ?string $tillBranchId): array
    {
        foreach ([[IdKind::Branch, $tillBranchId], [IdKind::Company, $tillCompanyId]] as [$kind, $tillId]) {
            if ($tillId === null || $tillId === '') {
                continue;
            }

            $map = IdMapping::withoutCompanyScope()->where('kind', $kind->value)->where('till_id', $tillId)->first();

            if ($map !== null) {
                return [$map->company_id, $kind === IdKind::Branch ? $map->portal_id : null];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: LocalLicenceKey, 1: bool} the record, and whether this report was refused
     */
    private function write(VerifiedSsposToken $token, TillRequest $till, string $installCode, ?string $companyId, ?string $branchId, string $via, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($token, $till, $installCode, $companyId, $branchId, $via, $now): array {
            $key = LocalLicenceKey::query()->where('licence_id', $token->licenceId())->lockForUpdate()->first();

            if ($key === null) {
                $key = new LocalLicenceKey(['licence_id' => $token->licenceId(), 'install_code' => $installCode, 'reported_via' => $via, 'first_seen_at' => $now, 'report_count' => 0]);
            } elseif ($key->install_code !== $installCode) {
                LocalLicenceKeyRefusal::query()->create([
                    'local_licence_key_id' => $key->id, 'install_code' => $installCode, 'install_id' => $till->installId,
                    'device_name' => self::cut($till->deviceName, 100), 'token_sha256' => $token->sha256(), 'refused_at' => $now,
                ]);
                $key->refused_count++;
                $key->last_refused_at = $now;
                $key->save();

                return [$key, true];
            }

            $key->fill([
                ...self::claims($token),
                'install_id' => $till->installId,
                'company_id' => $companyId ?? $key->company_id,
                'branch_id' => $branchId ?? $key->branch_id,
                'device_name' => self::cut($till->deviceName, 100) ?? $key->device_name,
                'app_version' => self::cut($till->appVersion, 40) ?? $key->app_version,
                'os' => self::cut($till->osLabel(), 120) ?? $key->os,
                'last_reported_at' => $now,
                'report_count' => $key->report_count + 1,
            ])->save();

            return [$key, false];
        });
    }

    /**
     * The token's fields as stored (the newest report's).
     *
     * @return array<string, mixed>
     */
    private static function claims(VerifiedSsposToken $token): array
    {
        return [
            'kid' => $token->kid(),
            'issuer' => self::cut(LocalToken::text($token->get('issuer')), 120),
            'kind' => $token->kind()?->value,
            'token_sha256' => $token->sha256(),
            'claimed_company_id' => self::cut(LocalToken::text($token->get('companyId')), 26),
            'claimed_branch_id' => self::cut(LocalToken::text($token->get('branchId')), 26),
            'business_name' => self::cut(LocalToken::text($token->get('businessName')), 100),
            'branch_name' => self::cut(LocalToken::text($token->get('branchName')), 100),
            'company' => is_array($token->get('company')) ? array_filter($token->get('company'), fn ($v) => is_string($v) && $v !== '') : null,
            'max_registers' => LocalToken::maxRegisters($token),
            'features' => LocalToken::features($token),
            'limits' => LocalToken::limits($token),
            'issued_at' => $token->date('issuedAt'),
            'valid_from' => $token->date('validFrom'),
            'expires_at' => $token->date('expiresAt'),
        ];
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null || $value === '' ? null : mb_substr($value, 0, $length);
    }
}
