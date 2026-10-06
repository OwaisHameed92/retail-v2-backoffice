<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Pharmacy\Support\ServiceModules;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\Actions\ResolveCurrentCompany;
use App\Domain\Tenancy\Actions\SwitchCurrentCompany;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Impersonation;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    private ?Company $resolvedCompany = null;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Tenant props (company, companies, companyRole, abilities) are closures, so they are resolved at render
     * time (after the `company` middleware has run) and only when a web user is signed in.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'twoFactorEnabled' => (bool) config('security.two_factor.enabled'),
            // Pakistan plan P0: this instance's country profile (currency, locale, time zone, tax name).
            'country' => app(Country::class)->toFrontend(),
            'company' => fn () => $this->currentCompany($request)?->only(['id', 'name', 'status']),
            'companies' => fn () => $this->companies($request),
            'companyRole' => fn () => $this->currentCompany($request)?->membership?->role->value,
            // Module 5.10: pharmacy and parcels leave the menu for businesses that do not use them.
            'abilities' => fn () => ServiceModules::visibleAbilities($this->currentCompany($request), array_map(
                fn (Ability $ability) => $ability->value,
                $this->currentCompany($request)?->membership?->role->abilities() ?? [],
            )),
            // Module 1.2: branch switcher, "login as customer" banner and flash toasts.
            'branches' => fn () => $this->branches(),
            'currentBranchId' => fn () => $this->currentBranchId($request),
            // Module 3.3: a user limited to one shop cannot switch to another (or to "All branches").
            'branchLocked' => fn () => app(CurrentCompany::class)->restrictedBranchId() !== null,
            'impersonation' => fn () => $this->impersonation($request),
            // Module 1.13: "Set up your Direct Debit — N days left" across the portal until a mandate exists.
            'billingNotice' => fn () => $this->billingNotice(),
            'flash' => fn () => $request->hasSession() ? [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ] : ['success' => null, 'error' => null],
        ]);
    }

    /**
     * Active branches of the current company for the top-bar switcher (only their own for a one-shop user). Only once the `company` middleware has
     * let the request through (never on the "on hold" page or outside the tenant area).
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    private function branches(): array
    {
        $tenancy = app(CurrentCompany::class);

        if (! $tenancy->has()) {
            return [];
        }

        return Branch::query()->active()
            ->when($tenancy->restrictedBranchId() !== null, fn ($query) => $query->whereKey($tenancy->restrictedBranchId()))
            ->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name])
            ->values()
            ->all();
    }

    /**
     * @return array{deadline: string, daysLeft: int, passed: bool, canSetUp: bool, url: string}|null
     */
    private function billingNotice(): ?array
    {
        $tenancy = app(CurrentCompany::class);
        $company = $tenancy->get();

        if ($company === null) {
            return null;
        }

        $state = MandateDeadline::state($company, app(BillingAccounts::class)->for($company));

        return $state === null ? null : $state + ['canSetUp' => $tenancy->can(Ability::BillingManage), 'url' => route('app.billing')];
    }

    private function currentBranchId(Request $request): ?string
    {
        if (! $request->hasSession() || ! app(CurrentCompany::class)->has()) {
            return null;
        }

        return app(ResolveCurrentBranch::class)->handle($request->session())?->id;
    }

    /**
     * @return array{userName: string, userEmail: string, companyName: string, adminName: string|null}|null
     */
    private function impersonation(Request $request): ?array
    {
        $user = $request->user();

        if (! $request->hasSession() || ! $user instanceof User) {
            return null;
        }

        $data = Impersonation::current($request->session());

        if ($data === null) {
            return null;
        }

        $admin = Auth::guard('admin')->user();

        return [
            'userName' => $user->name,
            'userEmail' => $user->email,
            'companyName' => (string) (Company::query()->whereKey($data['company_id'])->value('name') ?? ''),
            'adminName' => $admin instanceof Admin ? $admin->name : null,
        ];
    }

    /**
     * The current company (with its `membership` pivot) for a signed-in web user. Set by the `company`
     * middleware on /app routes; pages outside it (e.g. settings) still use the tenant layout, so resolve
     * it here read-only, without touching CurrentCompany.
     */
    private function currentCompany(Request $request): ?Company
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->resolvedCompany ??= app(CurrentCompany::class)->get()
            ?? app(ResolveCurrentCompany::class)->handle(
                $user,
                $request->hasSession() ? $request->session()->get(SwitchCurrentCompany::SESSION_KEY) : null,
            );
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function companies(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(ResolveCurrentCompany::class)->activeCompanies($user)
            ->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])
            ->values()
            ->all();
    }
}
