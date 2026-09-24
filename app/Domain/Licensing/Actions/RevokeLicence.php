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
 * Ends a licence for good: the key never works again and nothing can undo it. The till is free for a new
 * licence (IssueLicence). The record, its PC and its history are kept.
 */
class RevokeLicence
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence, string $reason): LicenceChange
    {
        $reason = LicenceGuard::reason($reason, 'Enter a reason for revoking the licence.');

        return DB::transaction(function () use ($licence, $reason) {
            $licence = LicenceGuard::lock($licence);

            if ($licence->isRevoked()) {
                throw ValidationException::withMessages(['status' => 'This licence is already revoked.']);
            }

            $from = $licence->status;
            $licence->status = LicenceStatus::Revoked;
            $licence->revoked_at = now()->toImmutable();
            $licence->revoked_reason = $reason;
            $licence->save();

            $this->audit->handle('licence.revoked', $licence, ['status' => $from->value], ['status' => $licence->status->value], ['reason' => $reason]);

            return new LicenceChange($licence, $from, $licence->status);
        });
    }
}
