<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Support\Impersonation;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Ends "login as customer": signs the customer user out of the web guard (without touching their other
 * devices or remember token), clears the tenant session keys and keeps the admin signed in.
 * Returns the company id that was being viewed, or null when nothing was active.
 */
class StopImpersonating
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Session $session, string $reason = 'returned'): ?string
    {
        $data = Impersonation::current($session);

        if ($data === null) {
            return null;
        }

        /** @var SessionGuard $web */
        $web = Auth::guard('web');
        $user = $web->user();
        $admin = Admin::query()->find($data['admin_id']);

        $web->logoutCurrentDevice();

        $session->forget([
            Impersonation::SESSION_KEY,
            SwitchCurrentCompany::SESSION_KEY,
            SwitchCurrentBranch::SESSION_KEY,
        ]);
        $session->migrate(true);

        $this->audit->handle('company.impersonation_ended', $user instanceof User ? $user : User::query()->find($data['user_id']), null, null, [
            'reason' => $reason,
            'started_at' => $data['started_at'],
        ], actor: $admin, companyId: $data['company_id']);

        return $data['company_id'];
    }
}
