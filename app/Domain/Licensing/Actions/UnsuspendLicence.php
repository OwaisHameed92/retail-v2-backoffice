<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\LicenceChange;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifts a licence suspension. The status goes back to what its dates say (issued, trial, active, grace or
 * expired). The till picks it up at its next check-in.
 */
class UnsuspendLicence
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence): LicenceChange
    {
        return DB::transaction(function () use ($licence) {
            $licence = LicenceGuard::lock($licence);

            if ($licence->status !== LicenceStatus::Suspended) {
                throw ValidationException::withMessages(['status' => 'This licence is not suspended.']);
            }

            $reason = $licence->suspended_reason;
            $licence->status = LicenceTerms::naturalStatus($licence, CarbonImmutable::now());
            $licence->suspended_at = null;
            $licence->suspended_reason = null;
            $licence->save();

            $this->audit->handle('licence.unsuspended', $licence, ['status' => LicenceStatus::Suspended->value], ['status' => $licence->status->value], array_filter([
                'previous_reason' => $reason,
            ]));

            return new LicenceChange($licence, LicenceStatus::Suspended, $licence->status);
        });
    }
}
