<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeactivateAdmin
{
    /**
     * Deactivate an admin so they can no longer sign in. Existing sessions end on their next request
     * (see AdminIsActive middleware) and "remember me" cookies stop working.
     *
     * @throws ValidationException when deactivating yourself or the last active owner
     */
    public function handle(Admin $admin, Admin $actor): Admin
    {
        if ($admin->is($actor)) {
            throw ValidationException::withMessages(['admin' => 'You cannot deactivate your own account.']);
        }

        return DB::transaction(function () use ($admin) {
            if (! $admin->is_active) {
                return $admin;
            }

            if ($admin->isOwner()) {
                $otherOwners = Admin::query()->active()->owners()
                    ->whereKeyNot($admin->getKey())->lockForUpdate()->exists();

                if (! $otherOwners) {
                    throw ValidationException::withMessages(['admin' => 'You cannot deactivate the last active owner.']);
                }
            }

            $admin->is_active = false;
            $admin->setRememberToken(Str::random(60));
            $admin->save();

            return $admin;
        });
    }
}
