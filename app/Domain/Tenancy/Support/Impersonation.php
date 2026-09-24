<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * "Login as customer" state, kept in the session under `impersonation`:
 * `{admin_id, user_id, company_id, started_at}`. The admin stays signed in on the `admin` guard while the
 * customer user is signed in on the `web` guard in the same session.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    /** Account screens a support admin must not change on the customer's behalf. */
    public const BLOCKED_ROUTES = ['profile.update', 'profile.destroy', 'password.update'];

    /**
     * @return array{admin_id: string, user_id: int, company_id: string, started_at: string}|null
     */
    public static function current(Session $session): ?array
    {
        $data = $session->get(self::SESSION_KEY);

        if (! is_array($data) || ! isset($data['admin_id'], $data['user_id'], $data['company_id'], $data['started_at'])) {
            return null;
        }

        return [
            'admin_id' => (string) $data['admin_id'],
            'user_id' => (int) $data['user_id'],
            'company_id' => (string) $data['company_id'],
            'started_at' => (string) $data['started_at'],
        ];
    }

    public static function active(Session $session): bool
    {
        return self::current($session) !== null;
    }

    /**
     * The session still belongs to the admin who started it (signed in, active, allowed) and the web user
     * is the impersonated user.
     */
    public static function isValid(Session $session, ?Admin $admin, ?User $user): bool
    {
        $data = self::current($session);

        return $data !== null
            && $admin !== null
            && $user !== null
            && $admin->getKey() === $data['admin_id']
            && $admin->hasAbility(AdminRole::TENANTS_MANAGE)
            && $user->getKey() === $data['user_id'];
    }
}
