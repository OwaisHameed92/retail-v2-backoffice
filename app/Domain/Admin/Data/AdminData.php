<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Models\Admin;

/**
 * Shapes an Admin for Inertia pages. Never includes the password or remember token.
 */
final class AdminData
{
    /**
     * @return array{id: string, name: string, email: string, role: string, roleLabel: string, isActive: bool, lastLoginAt: string|null, createdAt: string|null, twoFactorEnabled: bool}
     */
    public static function fromModel(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role->value,
            'roleLabel' => $admin->role->label(),
            'isActive' => $admin->is_active,
            'lastLoginAt' => $admin->last_login_at?->toIso8601String(),
            'createdAt' => $admin->created_at?->toIso8601String(),
            'twoFactorEnabled' => $admin->hasTwoFactorEnabled(),
        ];
    }

    /**
     * The signed-in admin, shared with every admin page as the "admin" prop.
     *
     * @return array{id: string, name: string, email: string, role: string, roleLabel: string, abilities: list<string>}
     */
    public static function forSession(Admin $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role->value,
            'roleLabel' => $admin->role->label(),
            'abilities' => $admin->role->abilities(),
        ];
    }
}
