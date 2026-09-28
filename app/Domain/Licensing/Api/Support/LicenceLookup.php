<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\RetiredLicenceKey;
use App\Domain\Shared\Exceptions\ApiException;

/**
 * Finds the licence of a key sent by a till: `whereIn('key_hash', hashCandidates())` (current and previous
 * APP_KEYs), across companies, soft-deleted licences excluded, revoked ones included (the actions decide).
 *
 * A key replaced by "Reissue key" was withdrawn: 410 key.expired. When the PC that was bound at the time keeps
 * using it, a `reissuedKeyUsed` alert tells staff the owner still needs the new key.
 */
final class LicenceLookup
{
    public function __construct(private readonly LicenceAlerts $alerts) {}

    /**
     * @throws ApiException key.not_found, key.expired
     */
    public function byKey(LicenceKey $key, TillRequest $request): Licence
    {
        $licence = Licence::withoutCompanyScope()->whereIn('key_hash', $key->hashCandidates())->first();

        if ($licence !== null) {
            return $licence;
        }

        $retired = RetiredLicenceKey::withoutCompanyScope()
            ->whereIn('key_hash', $key->hashCandidates())
            ->latest('retired_at')
            ->first();

        if ($retired === null) {
            throw LicenceApiErrors::keyNotFound();
        }

        $licence = Licence::withoutCompanyScope()->find($retired->licence_id);

        if ($licence !== null && $retired->bound_device_hash !== null && hash_equals($retired->bound_device_hash, DeviceHash::of($request->installId))) {
            $this->alerts->raise($licence, LicenceAlertType::ReissuedKeyUsed, $request, [
                'retiredKeyLast4' => $retired->key_last4,
                'retiredAt' => $retired->retired_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        }

        throw LicenceApiErrors::keyExpired();
    }
}
