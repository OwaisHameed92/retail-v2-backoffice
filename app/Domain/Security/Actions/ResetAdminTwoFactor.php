<?php

namespace App\Domain\Security\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Validation\ValidationException;

/**
 * An owner resets another admin's two-factor sign-in (lost phone and recovery codes). The secret, recovery codes and
 * remembered devices stop working at once; the admin must set up an authenticator app again at their next request.
 * Audited as `admin.two_factor_reset`.
 */
final class ResetAdminTwoFactor
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Admin $target, Admin $actor, ?string $reason = null): void
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages(['admin' => 'You cannot reset your own two-factor sign-in. Ask another owner.']);
        }

        if (! $target->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['admin' => "{$target->name} has not set up two-factor sign-in yet."]);
        }

        $target->clearTwoFactor();

        $this->audit->handle('admin.two_factor_reset', $target, ['twoFactor' => true], ['twoFactor' => false], array_filter([
            'reason' => $reason,
        ]), actor: $actor);
    }
}
