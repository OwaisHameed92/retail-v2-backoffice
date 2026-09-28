<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\OnboardTenant;
use App\Domain\Billing\Data\OnboardingBilling;
use App\Domain\Billing\Data\TenantBilling;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Data\LicenceFormData;
use App\Domain\Licensing\Data\TenantLicences;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Sync\Data\SyncKeyData;
use App\Domain\Tenancy\Actions\UpdateCompany;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Data\TenantActivity;
use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\Admin\UpdateTenantRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin tenant screens. Tenant-owned rows are read across companies with the documented escape hatches
 * (`withoutCompanyScope()` for the list counts, `CurrentCompany::runAs()` for one company's detail).
 */
class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $status = CompanyStatus::tryFrom((string) $request->string('status'));
        $table = TableQuery::from($request)
            ->sortable(['name', 'status', 'active_branches_count', 'active_registers_count', 'created_at'])
            ->defaultSort('created_at', 'desc');
        $search = $table->search();

        $query = Company::query()
            ->withCount([
                // Admin escape hatch: counting every company's rows (each count is still per company).
                'branches as active_branches_count' => fn (Builder $q) => $q->withoutGlobalScope(CompanyScope::class)->where('is_active', true),
                'registers as active_registers_count' => fn (Builder $q) => $q->withoutGlobalScope(CompanyScope::class)->where('is_active', true),
            ])
            ->addSelect(['owner_email' => User::query()->select('users.email')
                ->join('company_user', 'company_user.user_id', '=', 'users.id')
                ->whereColumn('company_user.company_id', 'companies.id')
                ->where('company_user.role', CompanyRole::Owner->value)
                ->where('company_user.is_active', true)
                ->orderBy('company_user.id')
                ->limit(1),
            ])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status?->value))
            ->when($search !== null, function (Builder $q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('companies.name', 'like', $like)
                    ->orWhere('companies.legal_name', 'like', $like)
                    ->orWhere('companies.email', 'like', $like)
                    ->orWhere('companies.phone', 'like', $like)
                    ->orWhereHas('users', fn (Builder $u) => $u->where('users.email', 'like', $like)));
            });

        return Inertia::render('admin/tenants/index', [
            'tenants' => $table->paginate($query, fn (Company $company) => TenantData::listRow($company)),
            'filters' => ['status' => $status?->value],
            'statuses' => CompanyStatus::options(),
            'counts' => Company::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'canManage' => $request->user('admin')?->can('create', Company::class) ?? false,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/tenants/create', [
            'nations' => Nation::options(),
            'maxTills' => NewTenant::MAX_TILLS,
            'plans' => LicenceData::planOptions(),
            'defaultPlanId' => DefaultPlan::portal()?->id,
            // Module 1.11: the licence form, with each plan's defaults.
            'licenceOptions' => LicenceFormData::options(),
            'planDefaults' => LicenceFormData::planDefaults(),
            // Module 1.13: the upfront payment (billing admins) and the Direct Debit deadline.
            'billing' => OnboardingBilling::options($request->user('admin')),
        ]);
    }

    public function store(StoreTenantRequest $request, OnboardTenant $onboardTenant): RedirectResponse
    {
        $company = $onboardTenant->handle($request->toNewTenant(), $request->upfront());

        $keys = $company->licences()->withoutGlobalScopes()->count();

        return redirect()->route('admin.tenants.show', $company)
            ->with('success', $keys > 0
                ? "{$company->name} is set up. The owner gets a welcome email with the licence keys (and a set-password email if they are new)."
                : "{$company->name} is set up. No plan exists yet, so the tills have no licences: create a plan, then issue them.");
    }

    public function show(Request $request, Company $company, CurrentCompany $tenancy): Response
    {
        /** @var Collection<int, Branch> $branches */
        $branches = $tenancy->runAs($company, fn () => Branch::query()->with('registers')->orderByDesc('is_active')->orderBy('name')->get());

        $members = $company->users()->orderByPivot('is_active', 'desc')->orderBy('users.name')->get();

        $activityTable = TableQuery::from($request)->defaultSort('created_at', 'desc')->defaultPerPage(10);
        $activity = $activityTable->paginator(AuditLog::query()->where('company_id', $company->id));
        $presenter = new TenantActivity(collect($activity->items()));

        $admin = $request->user('admin');
        // Billing is for owner and accounts only (billing.manage), reading included: no tab data for others.
        $billingAccess = $admin?->hasAbility(AdminRole::BILLING_MANAGE) ?? false;

        $syncKeys = SyncKeyData::forBranches($company, $branches);

        return Inertia::render('admin/tenants/show', [
            'tenant' => TenantData::company($company),
            'licensing' => TenantLicences::for($company, CarbonImmutable::now()),
            'billing' => $billingAccess ? TenantBilling::for($company, true) : null,
            'plans' => LicenceData::planOptions(),
            'stats' => [
                'branches' => $branches->where('is_active', true)->count(),
                'branchesInactive' => $branches->where('is_active', false)->count(),
                'tills' => $branches->where('is_active', true)->sum(fn (Branch $b) => $b->registers->where('is_active', true)->count()),
                'users' => $members->filter(fn (User $u) => (bool) $u->getRelation('membership')->is_active)->count(),
            ],
            'branches' => $branches->map(fn (Branch $branch) => TenantData::branch($branch) + ['licence' => LicenceFormData::branch($branch), 'syncKey' => $syncKeys[$branch->id]])->values(),
            // Module 1.11: the licence form (company branch limits and the options).
            'branchLimits' => LicenceFormData::limits($company),
            'licenceOptions' => LicenceFormData::options(),
            'members' => $members->map(fn (User $user) => TenantData::member($user))->values(),
            'activity' => [
                'data' => array_map(fn (AuditLog $entry) => $presenter->row($entry), $activity->items()),
                'meta' => [
                    'page' => $activity->currentPage(),
                    'perPage' => $activity->perPage(),
                    'total' => $activity->total(),
                    'lastPage' => $activity->lastPage(),
                    'search' => null,
                    'sort' => null,
                    'direction' => 'desc',
                ],
            ],
            'nations' => Nation::options(),
            'roles' => TenantData::roleOptions(),
            'maxTills' => NewTenant::MAX_TILLS,
            'can' => [
                'manage' => $request->user('admin')?->can('update', $company) ?? false,
                'impersonate' => $request->user('admin')?->can('impersonate', $company) ?? false,
                'manageLicences' => $admin?->hasAbility(AdminRole::LICENCES_MANAGE) ?? false,
            ],
        ]);
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('admin/tenants/edit', [
            'tenant' => TenantData::company($company),
            'businessTypes' => BusinessType::options(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Company $company, UpdateCompany $updateCompany): RedirectResponse
    {
        $updateCompany->handle($company, $request->details());

        return redirect()->route('admin.tenants.show', $company)->with('success', 'Business details saved.');
    }
}
