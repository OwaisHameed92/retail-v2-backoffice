<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\LicenceChange;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Frees a licence from its PC (new PC, reinstall on new hardware). The key stays the same; the next PC that
 * activates with it becomes the bound one. The old PC keeps trading until its offline token runs out (module 1.5).
 */
class ResetDevice
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence): LicenceChange
    {
        return DB::transaction(function () use ($licence) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'reset its PC');

            if (! $licence->isBound()) {
                throw ValidationException::withMessages(['status' => 'This licence is not bound to a PC yet, so there is nothing to reset.']);
            }

            $before = ['device_id' => $licence->device_id, 'device_name' => $licence->device_name, 'bound_at' => $licence->bound_at?->toIso8601String()];

            $licence->device_id = null;
            $licence->device_name = null;
            $licence->bound_at = null;
            $licence->save();

            $this->audit->handle('licence.device_reset', $licence, $before, ['device_id' => null, 'device_name' => null, 'bound_at' => null]);

            return new LicenceChange($licence, $licence->status, $licence->status);
        });
    }
}
