<?php

namespace App\Domain\Licensing\Listeners;

use App\Domain\Licensing\Support\IssuedKeys;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Tenancy\Events\TenantCreated;

/**
 * After CreateTenant, the owner gets the welcome email with every new till's licence key (module 1.7's
 * WelcomeTenantMail, queued after commit, encrypted on the queue). It is separate from the 7-day
 * "set your password" email. No keys (no plan existed) means no welcome email: staff issue them later.
 */
final class SendWelcomeEmailWithKeys
{
    public function __construct(
        private readonly IssuedKeys $issuedKeys,
        private readonly LicenceMailer $mailer,
    ) {}

    public function handle(TenantCreated $event): void
    {
        $issued = $this->issuedKeys->pullForCompany($event->company->id);

        if ($issued === []) {
            return;
        }

        $this->mailer->welcome($event->company, $event->owner, $issued);
    }
}
