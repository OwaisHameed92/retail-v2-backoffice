<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Api\Support\DeviceHash;
use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\RetiredLicenceKey;
use App\Domain\Licensing\Support\InstallRelease;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\UniqueLicenceKey;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a licence's key (lost or leaked key). The old key stops working at once; the PC binding is cleared
 * so the new key can be entered on any PC, and the old PC's next validate answers `released`. Status, plan and
 * dates are kept. The old key's hash moves to `retired_licence_keys`: activating it answers 410 key.expired
 * (and alerts staff when the old PC keeps using it).
 */
class ReissueKey
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly InstallRelease $release,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence): IssuedLicence
    {
        return DB::transaction(function () use ($licence) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'replace its key');

            $before = ['key_last4' => $licence->key_last4, 'device_id' => $licence->device_id, 'device_name' => $licence->device_name];
            $key = UniqueLicenceKey::generate();

            // Module 1.5: remember the old key's hash (and a hash of its PC) so the API can tell staff when that
            // PC keeps using it. The old key itself is answered with key.expired.
            RetiredLicenceKey::withoutCompanyScope()->create([
                'company_id' => $licence->company_id,
                'licence_id' => $licence->id,
                'key_hash' => $licence->key_hash,
                'key_last4' => $licence->key_last4,
                'bound_device_hash' => $licence->device_id !== null ? DeviceHash::of($licence->device_id) : null,
                'retired_at' => CarbonImmutable::now(),
            ]);

            $this->release->apply($licence, CarbonImmutable::now());
            $licence->key_hash = $key->hash();
            $licence->key_last4 = $key->last4();
            $licence->save();

            $this->audit->handle('licence.key_reissued', $licence, $before, [
                'key_last4' => $licence->key_last4,
                'device_id' => null,
                'device_name' => null,
            ]);

            return new IssuedLicence($licence, $key, replacedKey: true);
        });
    }
}
