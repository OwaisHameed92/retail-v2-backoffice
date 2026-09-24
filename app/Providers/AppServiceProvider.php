<?php

namespace App\Providers;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin role abilities (AdminRole) usable as can:tenants.manage etc. Other abilities fall through to policies.
        Gate::before(function (mixed $user, string $ability): ?bool {
            if ($user instanceof Admin && in_array($ability, AdminRole::allAbilities(), true)) {
                return $user->hasAbility($ability);
            }

            return null;
        });
    }
}
