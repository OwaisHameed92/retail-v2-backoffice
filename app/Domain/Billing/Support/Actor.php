<?php

namespace App\Domain\Billing\Support;

use Illuminate\Support\Facades\Auth;

/**
 * The signed-in admin's id for the created/issued/voided/received-by columns (null for billing:run).
 */
final class Actor
{
    public static function adminId(): ?string
    {
        if (config('auth.guards.admin') === null) {
            return null;
        }

        $id = Auth::guard('admin')->id();

        return $id === null ? null : (string) $id;
    }
}
