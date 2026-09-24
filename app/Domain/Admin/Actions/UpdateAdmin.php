<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateAdmin
{
    /**
     * Update an admin's details. A null password keeps the current one.
     *
     * @throws ValidationException when the email is taken or the last active owner would lose the owner role
     */
    public function handle(Admin $admin, string $name, string $email, AdminRole $role, ?string $password = null): Admin
    {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($admin, $name, $email, $role, $password) {
            $taken = Admin::query()->where('email', $email)->whereKeyNot($admin->getKey())->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => 'An admin with this email already exists.']);
            }

            $losesOwner = $admin->isOwner() && $admin->is_active && $role !== AdminRole::Owner;

            if ($losesOwner && ! $this->hasOtherActiveOwner($admin)) {
                throw ValidationException::withMessages(['role' => 'There must be at least one active owner.']);
            }

            $admin->fill([
                'name' => trim($name),
                'email' => $email,
                'role' => $role,
            ]);

            if ($password !== null && $password !== '') {
                $admin->password = $password;
                $admin->setRememberToken(Str::random(60));
            }

            $admin->save();

            return $admin;
        });
    }

    private function hasOtherActiveOwner(Admin $admin): bool
    {
        return Admin::query()->active()->owners()->whereKeyNot($admin->getKey())->lockForUpdate()->exists();
    }
}
