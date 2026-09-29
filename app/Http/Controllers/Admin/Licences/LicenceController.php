<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\UpdateLicenceNotes;
use App\Domain\Licensing\Data\LicenceActivity;
use App\Domain\Licensing\Data\LicenceAlertData;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Data\LicenceTimeline;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Queries\LicenceQuery;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillHealth\Queries\CompanyHealth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LicenceNotesRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Licence list and detail (module 1.3). Reading needs `tenants.view`, changes `licences.manage` (route middleware).
 */
class LicenceController extends Controller
{
    use FindsLicences;

    public function index(Request $request): Response|RedirectResponse
    {
        // A full licence key must never sit in a URL (contract §17.11 rule 12): drop it, never search or echo it.
        if (LicenceKey::tryParse(trim((string) $request->query('search', ''))) !== null) {
            return redirect()->route('admin.licences.index', $request->except('search'))
                ->with('error', 'For security, search the list by the last 4 characters. To find a full key, use the top-bar search (Ctrl K).');
        }

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(LicenceStatus::class)],
            'plan' => ['nullable', 'string', 'max:26'],
            'company' => ['nullable', 'string', 'max:26'],
        ]);
        $status = LicenceStatus::tryFrom((string) ($filters['status'] ?? ''));
        $now = CarbonImmutable::now();

        $table = TableQuery::from($request)->sortable(LicenceQuery::SORTABLE)->defaultSort('created_at', 'desc');

        $base = LicenceQuery::admin()
            ->when($filters['plan'] ?? null, fn ($q, $plan) => $q->where('licences.plan_id', $plan))
            ->when($filters['company'] ?? null, fn ($q, $company) => $q->where('licences.company_id', $company));

        if ($table->search() !== null) {
            LicenceQuery::search($base, (string) $table->search());
        }

        $counts = LicenceQuery::countsByStatus($base, $now);
        $query = $base->clone();

        if ($status !== null) {
            LicenceQuery::whereEffectiveStatus($query, $status, $now);
        }

        $company = isset($filters['company']) ? Company::query()->withTrashed()->find($filters['company']) : null;

        return Inertia::render('admin/licences/index', [
            'licences' => $table->paginate($query, fn (Licence $licence) => LicenceData::row($licence, $now)),
            'filters' => [
                'status' => $status?->value,
                'plan' => $filters['plan'] ?? null,
                'company' => $company === null ? null : ['id' => $company->id, 'name' => $company->name],
            ],
            'statuses' => LicenceStatus::options(),
            'counts' => $counts,
            'total' => Licence::withoutCompanyScope()->count(),
            'plans' => Plan::withTrashed()->whereHas('licences')->ordered()->get()->map(fn (Plan $plan) => ['value' => $plan->id, 'label' => $plan->name])->values(),
        ]);
    }

    public function show(Request $request, string $licence): Response
    {
        $model = $this->findLicence($licence);
        $now = CarbonImmutable::now();
        // Module 2.7: this till's health (only while the licence is the till's live one) and its shop's sync.
        $health = CompanyHealth::for($model->company_id, $now);
        $till = $health['tills'][$model->register_id] ?? null;

        return Inertia::render('admin/licences/show', [
            'licence' => LicenceData::detail($model, $now),
            'timeline' => LicenceTimeline::for($model, $now),
            'activity' => LicenceActivity::forLicence($model),
            'alerts' => LicenceAlertData::forLicence($model),
            'health' => $till !== null && $till['licenceId'] === $model->id ? [
                'till' => $till,
                'shop' => $health['branches'][$model->branch_id] ?? null,
                'thresholds' => $health['thresholds'],
            ] : null,
            'plans' => LicenceData::planOptions(),
            'can' => ['manage' => $request->user('admin')?->hasAbility(AdminRole::LICENCES_MANAGE) ?? false],
        ]);
    }

    public function updateNotes(LicenceNotesRequest $request, string $licence, UpdateLicenceNotes $updateNotes): RedirectResponse
    {
        $updateNotes->handle($this->findLicence($licence), $request->input('notes'));

        return back()->with('success', 'Notes saved.');
    }
}
