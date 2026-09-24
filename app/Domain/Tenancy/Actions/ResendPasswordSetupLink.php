<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Mail\Actions\SendPasswordSetupLink;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;

/**
 * Admin "Send set-password email" for a company user: sends the branded 7-day setup link (module 1.7's
 * SendPasswordSetupLink) and records it in the company's activity log. The token is never logged.
 */
class ResendPasswordSetupLink
{
    public function __construct(
        private readonly SendPasswordSetupLink $sendPasswordSetupLink,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(User $user, Company $company): void
    {
        $this->sendPasswordSetupLink->handle($user, $company->name, $company->id);

        $this->audit->handle('company.user_password_link_sent', $user, null, null, ['email' => $user->email], companyId: $company->id);
    }
}
