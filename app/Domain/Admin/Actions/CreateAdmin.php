<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateAdmin
{
    /**
     * Create an active admin. The password is hashed by the model cast.
     *
     * @throws ValidationException when the email is already taken
     */
    public function handle(string $name, string $email, string $password, AdminRole $role): Admin
    {
        $email = Str::lower(trim($email));

        if (Admin::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'An admin with this email already exists.']);
        }

        return Admin::query()->create([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
