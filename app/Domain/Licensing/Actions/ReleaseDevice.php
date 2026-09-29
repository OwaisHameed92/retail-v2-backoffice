<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\LicenceChange;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\InstallRelease;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Release" (contract v1.4.1 §17.15.3): frees a licence key from its PC (reinstall, new PC, stolen PC).
 * The old PC's next `licence/validate` answers `released` and it locks; the same key can then be activated on
 * another PC. Status, plan and dates are kept.
 */
class ReleaseDevice
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly InstallRelease $release,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence): LicenceChange
    {
        return DB::transaction(function () use ($licence) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'release it from its PC');

            if (! $licence->isBound()) {
                throw ValidationException::withMessages(['status' => 'This licence is not in use on a PC, so there is nothing to release.']);
            }

            $before = $this->release->apply($licence, CarbonImmutable::now());

            $this->audit->handle('licence.device_released', $licence, $before, InstallRelease::after());

            return new LicenceChange($licence, $licence->status, $licence->status);
        });
    }
}
