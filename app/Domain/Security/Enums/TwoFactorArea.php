<?php

namespace App\Domain\Security\Enums;

/**
 * Where a two-factor sign-in happens. The value is the auth guard: admins (`admin`, two-factor required) and portal
 * users (`web`, optional unless their company requires it).
 */
enum TwoFactorArea: string
{
    case Admin = 'admin';
    case Web = 'web';

    public function challengeRoute(): string
    {
        return $this === self::Admin ? 'admin.two-factor.challenge' : 'two-factor.challenge';
    }

    public function setupRoute(): string
    {
        return $this === self::Admin ? 'admin.two-factor.setup' : 'two-factor.setup';
    }

    public function homeRoute(): string
    {
        return $this === self::Admin ? 'admin.dashboard' : 'app.dashboard';
    }

    public function loginRoute(): string
    {
        return $this === self::Admin ? 'admin.login' : 'login';
    }

    /** Name shown in the authenticator app next to the account email. */
    public function issuer(): string
    {
        return $this === self::Admin ? 'Switch & Save admin' : 'Switch & Save';
    }
}
