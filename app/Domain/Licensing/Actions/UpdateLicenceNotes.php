<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

/**
 * Staff-only notes on a licence (never sent to the till or the customer).
 */
class UpdateLicenceNotes
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Licence $licence, ?string $notes): Licence
    {
        $notes = trim((string) $notes);
        $notes = $notes === '' ? null : mb_substr($notes, 0, 5000);

        return DB::transaction(function () use ($licence, $notes) {
            $licence = LicenceGuard::lock($licence);

            if ($licence->notes === $notes) {
                return $licence;
            }

            $before = ['notes' => $licence->notes];
            $licence->notes = $notes;
            $licence->save();

            $this->audit->handle('licence.notes_updated', $licence, $before, ['notes' => $notes]);

            return $licence;
        });
    }
}
