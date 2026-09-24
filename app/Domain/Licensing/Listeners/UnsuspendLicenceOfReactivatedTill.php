<?php

namespace App\Domain\Licensing\Listeners;

use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Actions\UnsuspendLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Events\RegisterReactivated;

/**
 * Reactivating a till lifts its licence suspension only when the till's deactivation caused it. A suspension
 * staff made for another reason (unpaid, misuse) stays.
 */
final class UnsuspendLicenceOfReactivatedTill
{
    public function __construct(private readonly UnsuspendLicence $unsuspendLicence) {}

    public function handle(RegisterReactivated $event): void
    {
        $licence = Licence::withoutCompanyScope()->live()->where('register_id', $event->register->id)->first();

        if ($licence === null || $licence->status !== LicenceStatus::Suspended || $licence->suspended_reason !== SuspendLicence::TILL_DEACTIVATED) {
            return;
        }

        $this->unsuspendLicence->handle($licence);
    }
}
