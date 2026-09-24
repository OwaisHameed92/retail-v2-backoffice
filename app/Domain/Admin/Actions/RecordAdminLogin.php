<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\Admin;

class RecordAdminLogin
{
    public function handle(Admin $admin): Admin
    {
        $admin->forceFill(['last_login_at' => now()])->save();

        return $admin;
    }
}
