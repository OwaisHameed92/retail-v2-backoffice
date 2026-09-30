<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use SensitiveParameter;

/**
 * A portal user changes their own password (module 4.1; the current password is checked by the controller).
 * Audited without the password.
 */
class ChangeOwnPassword
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(User $user, #[SensitiveParameter] string $password, ?string $companyId): void
    {
        $user->update(['password' => $password]);

        $this->audit->handle('user.password_changed', $user, companyId: $companyId);
    }
}
