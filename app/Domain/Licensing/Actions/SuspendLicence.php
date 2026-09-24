<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\LicenceChange;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Locks one licence until it is unsuspended. The till stops trading at its next check-in. Reversible
 * (UnsuspendLicence); use RevokeLicence to end a licence for good.
 */
class SuspendLicence
{
    /** Reason used when a till is deactivated (1.2's DeactivateRegister); reactivating lifts only this one. */
    public const TILL_DEACTIVATED = 'Till deactivated';

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence, string $reason): LicenceChange
    {
        $reason = LicenceGuard::reason($reason, 'Enter a reason for the suspension.');

        return DB::transaction(function () use ($licence, $reason) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'suspend it');

            if ($licence->status === LicenceStatus::Suspended) {
                throw ValidationException::withMessages(['status' => 'This licence is already suspended.']);
            }

            $from = $licence->status;
            $licence->status = LicenceStatus::Suspended;
            $licence->suspended_at = now()->toImmutable();
            $licence->suspended_reason = $reason;
            $licence->save();

            $this->audit->handle('licence.suspended', $licence, ['status' => $from->value], ['status' => $licence->status->value], ['reason' => $reason]);

            return new LicenceChange($licence, $from, $licence->status);
        });
    }
}
