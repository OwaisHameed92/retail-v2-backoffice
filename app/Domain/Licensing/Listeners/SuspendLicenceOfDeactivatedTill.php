<?php

namespace App\Domain\Licensing\Listeners;

use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Events\RegisterDeactivated;

/**
 * A deactivated till's licence is suspended with the reason "Till deactivated" (unless it is already suspended
 * for another reason or revoked), so the PC stops trading at its next check-in.
 */
final class SuspendLicenceOfDeactivatedTill
{
    public function __construct(private readonly SuspendLicence $suspendLicence) {}

    public function handle(RegisterDeactivated $event): void
    {
        $licence = Licence::withoutCompanyScope()->live()->where('register_id', $event->register->id)->first();

        if ($licence === null || $licence->status === LicenceStatus::Suspended) {
            return;
        }

        $this->suspendLicence->handle($licence, SuspendLicence::TILL_DEACTIVATED);
    }
}
