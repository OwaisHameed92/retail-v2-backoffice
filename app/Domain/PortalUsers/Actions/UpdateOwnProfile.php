<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;

/**
 * A portal user changes their own name or email (module 4.1). A new email must be verified again. Audited in the
 * current business's log.
 */
class UpdateOwnProfile
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(User $user, string $name, string $email, ?string $companyId): void
    {
        $before = ['name' => $user->name, 'email' => $user->email];

        $user->fill(['name' => trim($name), 'email' => $email]);

        if (! $user->isDirty()) {
            return;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->audit->handle('user.profile_updated', $user, $before, ['name' => $user->name, 'email' => $user->email], companyId: $companyId);
    }
}
