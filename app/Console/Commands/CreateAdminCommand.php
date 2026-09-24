<?php

namespace App\Console\Commands;

use App\Domain\Admin\Actions\CreateAdmin;
use App\Domain\Admin\Enums\AdminRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an SSPOS admin (e.g. the first owner). The password is always typed in, never stored in code.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {email : Email address used to sign in at /admin/login}
        {--name= : Display name (asked for when missing)}
        {--role=owner : One of owner, sales, support, accounts}';

    protected $description = 'Create an admin user for the /admin area';

    public function handle(CreateAdmin $createAdmin): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $role = (string) $this->option('role');

        $password = (string) $this->secret('Password (min 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'role' => $role,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'email', 'max:255', Rule::unique('admins', 'email')],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = $createAdmin->handle($name, $email, $password, AdminRole::from($role));

        $this->info("Admin {$admin->email} created with role {$admin->role->label()}.");

        return self::SUCCESS;
    }
}
