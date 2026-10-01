<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Throwable;

/**
 * "Login as customer" state, kept in the session under `impersonation`:
 * `{admin_id, user_id, company_id, started_at}`. The admin stays signed in on the `admin` guard while the
 * customer user is signed in on the `web` guard in the same session. It ends by itself after
 * config('security.impersonation_minutes') and only ever shows the business it was started for (M5).
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    /**
     * Account screens a support admin must not change on the customer's behalf, and the business switcher (the
     * session is pinned to the business it was started for, security review M5).
     */
    public const BLOCKED_ROUTES = [
        'profile.update', 'profile.destroy', 'password.update', 'app.company.switch',
        'security.two-factor.destroy', 'security.recovery-codes', 'security.company',
    ];

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

    /** Started more than config('security.impersonation_minutes') ago (security review M5), or with no valid start. */
    public static function expired(Session $session): bool
    {
        $data = self::current($session);

        if ($data === null) {
            return false;
        }

        try {
            $started = CarbonImmutable::parse($data['started_at']);
        } catch (Throwable) {
            return true;
        }

        return $started->addMinutes(max(1, (int) config('security.impersonation_minutes', 60)))->isPast();
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
