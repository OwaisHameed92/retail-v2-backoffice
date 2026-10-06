<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an unused key's activate-by date (module 1.11) to the end of a later day (shop time zone). Only for a
 * key that was never activated and is not revoked; after the date `licence/activate` answers 410 key.expired.
 */
class ExtendActivateBy
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence, CarbonInterface $until): Licence
    {
        return DB::transaction(function () use ($licence, $until) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'extend its activate-by date');

            if ($licence->activated_at !== null) {
                throw ValidationException::withMessages(['activate_by' => 'This key is already activated, so it has no activate-by date.']);
            }

            $activateBy = RenewalTerm::endOfLocalDay(CarbonImmutable::instance($until)->setTimezone(Country::zone())->format('Y-m-d'));

            if ($activateBy->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw ValidationException::withMessages(['activate_by' => 'Choose today or a later date.']);
            }

            $before = ['activate_by' => $licence->activate_by?->toIso8601String()];
            $licence->activate_by = $activateBy;
            $licence->save();

            $this->audit->handle('licence.activate_by_changed', $licence, $before, ['activate_by' => $activateBy->toIso8601String()]);

            return $licence;
        });
    }
}
