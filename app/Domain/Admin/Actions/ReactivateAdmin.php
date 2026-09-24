<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\Admin;

class ReactivateAdmin
{
    public function handle(Admin $admin): Admin
    {
        if (! $admin->is_active) {
            $admin->is_active = true;
            $admin->save();
        }

        return $admin;
    }
}
