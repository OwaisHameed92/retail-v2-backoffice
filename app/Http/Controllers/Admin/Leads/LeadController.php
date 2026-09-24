<?php

namespace App\Http\Controllers\Admin\Leads;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Actions\CreateLead;
use App\Domain\Leads\Actions\UpdateLead;
use App\Domain\Leads\Data\DuplicateMatch;
use App\Domain\Leads\Data\LeadData;
use App\Domain\Leads\Data\LeadFilters;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Leads\Queries\LeadQuery;
use App\Domain\Leads\Queries\LeadStats;
use App\Domain\Leads\Support\LeadDuplicates;
use App\Domain\Leads\Support\TrialSuggestion;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\Nation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leads\FollowUpTime;
use App\Http\Requests\Admin\Leads\LeadRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin lead screens (module 1.6): list/board, detail, add and edit. Status changes live in LeadActionController,
 * the trial approval in LeadApprovalController.
 */
class LeadController extends Controller
{
    /** Cards per board column; the column header shows the full count. */
    private const BOARD_LIMIT = 25;

    public function index(Request $request): Response
    {
        $admin = $request->user('admin');
        $filters = LeadFilters::fromRequest($request);
        $view = $request->string('view')->toString() === 'board' && $filters->status !== 'archived' ? 'board' : 'list';
        $table = TableQuery::from($request)
            ->sortable(['business_name', 'status', 'shops_count', 'tills_count', 'follow_up_at', 'created_at'])
            ->defaultSort('created_at', 'desc');

        return Inertia::render('admin/leads/index', [
            'view' => $view,
            'leads' => fn () => $view === 'list' ? $this->list($table, $filters, $admin?->id) : null,
            'board' => fn () => $view === 'board' ? $this->board($filters, $table->search(), $admin?->id) : null,
            'filters' => $filters->toArray(),
            'search' => $table->search(),
            'counts' => LeadQuery::statusCounts(),
            'mine' => Lead::query()->where('assigned_admin_id', $admin?->id)->count(),
            'stats' => LeadStats::compute()->toArray(),
            'statuses' => LeadStatus::options(),
            'options' => LeadData::options(),
            'can' => [
                'create' => $admin?->can('create', Lead::class) ?? false,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/leads/create', [
            'options' => LeadData::options(),
            'defaultFollowUpTime' => FollowUpTime::DEFAULT_TIME,
        ]);
    }

    public function store(LeadRequest $request, CreateLead $createLead): RedirectResponse
    {
        $lead = $createLead->handle($request->details(), $request->assignee(), $request->followUpAt());

        return redirect()->route('admin.leads.show', $lead)->with('success', "{$lead->business_name} added. The team has been emailed.");
    }

    public function show(Request $request, Lead $lead): Response
    {
        $admin = $request->user('admin');
        $lead->load(['assignedAdmin', 'convertedBy', 'company']);
        $notes = $lead->notes()->with('admin')->limit(200)->get();
        $canApprove = ($admin?->can('approve', $lead) ?? false) && $lead->isOpen();

        return Inertia::render('admin/leads/show', [
            'lead' => LeadData::detail($lead),
            'notes' => $notes->map(fn (LeadNote $note) => LeadData::note($note))->values(),
            'duplicates' => array_map(fn (DuplicateMatch $match) => $match->toArray(), LeadDuplicates::for($lead)),
            'approval' => $canApprove ? $this->approval($lead) : null,
            'options' => LeadData::options(),
            'defaultFollowUpTime' => FollowUpTime::DEFAULT_TIME,
            'can' => [
                'update' => $admin?->can('update', $lead) ?? false,
                'approve' => $canApprove,
                'viewTenant' => $admin?->hasAbility(AdminRole::TENANTS_VIEW) ?? false,
            ],
        ]);
    }

    public function edit(Lead $lead): Response|RedirectResponse
    {
        if ($lead->isConverted()) {
            return redirect()->route('admin.leads.show', $lead)->with('error', 'This lead is already a customer. Edit the details on the tenant page instead.');
        }

        return Inertia::render('admin/leads/edit', [
            'lead' => LeadData::listRow($lead),
            'form' => LeadData::form($lead),
            'options' => LeadData::options(),
        ]);
    }

    public function update(LeadRequest $request, Lead $lead, UpdateLead $updateLead): RedirectResponse
    {
        $updateLead->handle($lead, $request->details($lead));

        return redirect()->route('admin.leads.show', $lead)->with('success', 'Lead details saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function list(TableQuery $table, LeadFilters $filters, ?string $adminId): array
    {
        $query = LeadQuery::filtered($filters, $table->search(), $adminId)->with('assignedAdmin');

        if ($table->sort() === 'follow_up_at') {
            // Leads without a follow-up go last in both directions.
            $query->orderByRaw('follow_up_at is null');
        }

        return $table->paginate($query, fn (Lead $lead) => LeadData::listRow($lead));
    }

    /**
     * @return list<array{status: string, label: string, total: int, leads: list<array<string, mixed>>}>
     */
    private function board(LeadFilters $filters, ?string $search, ?string $adminId): array
    {
        $columns = [];

        foreach (LeadStatus::pipeline() as $status) {
            $query = fn (): Builder => LeadQuery::filtered($filters, $search, $adminId, withStatus: false)->where('status', $status->value);

            $cards = $query()->with('assignedAdmin')
                ->when($status->isOpen(), fn (Builder $q) => $q->orderByRaw('follow_up_at is null')->orderBy('follow_up_at'))
                ->orderByDesc('updated_at')
                ->limit(self::BOARD_LIMIT)
                ->get();

            $columns[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'total' => $query()->count(),
                'leads' => $cards->map(fn (Lead $lead) => LeadData::listRow($lead))->values()->all(),
            ];
        }

        return $columns;
    }

    /**
     * What the approval dialog starts from, and what it needs to explain the outcome.
     *
     * @return array<string, mixed>
     */
    private function approval(Lead $lead): array
    {
        $suggestion = TrialSuggestion::for($lead);
        $defaultPlan = DefaultPlan::portal();

        return [
            'suggestion' => $suggestion->toArray(),
            'plans' => LicenceData::planOptions(),
            'defaultPlanId' => $defaultPlan?->id,
            'trialDays' => $defaultPlan->trial_days ?? 7,
            'plansTrialDays' => Plan::query()->where('is_active', true)->pluck('trial_days', 'id'),
            'ownerHasLogin' => $lead->email !== null && User::query()->where('email', $lead->email)->exists(),
            'nations' => Nation::options(),
            'maxTillsPerShop' => NewTenant::MAX_TILLS,
            'maxShops' => Lead::MAX_SHOPS,
        ];
    }
}
