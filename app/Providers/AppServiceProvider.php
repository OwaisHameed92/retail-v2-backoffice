<?php

namespace App\Providers;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Listeners\SyncDirectDebitOnTillChange;
use App\Domain\Billing\GoCardless\Support\SdkGoCardlessClient;
use App\Domain\Licensing\Listeners\IssueLicenceForNewTill;
use App\Domain\Licensing\Listeners\SendWelcomeEmailWithKeys;
use App\Domain\Licensing\Listeners\SuspendLicenceOfDeactivatedTill;
use App\Domain\Licensing\Listeners\UnsuspendLicenceOfReactivatedTill;
use App\Domain\Tenancy\Events\BranchAdded;
use App\Domain\Tenancy\Events\BranchDeactivated;
use App\Domain\Tenancy\Events\BranchReactivated;
use App\Domain\Tenancy\Events\RegisterAdded;
use App\Domain\Tenancy\Events\RegisterDeactivated;
use App\Domain\Tenancy\Events\RegisterReactivated;
use App\Domain\Tenancy\Events\TenantCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GoCardless Direct Debit (module 1.12). Tests bind FakeGoCardlessClient::install().
        $this->app->singleton(GoCardlessClient::class, SdkGoCardlessClient::class);
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

        // Licences follow the tills (module 1.3). Synchronous: they run inside the tenancy actions' transactions.
        Event::listen(RegisterAdded::class, IssueLicenceForNewTill::class);
        Event::listen(RegisterDeactivated::class, SuspendLicenceOfDeactivatedTill::class);
        Event::listen(RegisterReactivated::class, UnsuspendLicenceOfReactivatedTill::class);
        Event::listen(TenantCreated::class, SendWelcomeEmailWithKeys::class);

        // Direct Debit follows the live tills (module 1.12). Queued, after the till's transaction commits.
        Event::listen([RegisterAdded::class, RegisterDeactivated::class, RegisterReactivated::class, BranchAdded::class, BranchDeactivated::class, BranchReactivated::class], SyncDirectDebitOnTillChange::class);
    }
}
