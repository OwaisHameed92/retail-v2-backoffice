<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Plans\Actions\CreatePlan;
use App\Domain\Plans\Actions\UpdatePlan;
use App\Domain\Plans\Data\PlanActivity;
use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanStatus;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\TableQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscription plans (ability `billing.manage`, enforced by route middleware and the form requests).
 */
class PlanController extends Controller
{
    use PlanToasts;

    /** `status` filter values besides the PlanStatus cases. "" = everything except archived. */
    private const STATUS_ALL = 'all';

    public function index(Request $request): Response
    {
        $filter = $request->string('status')->value();
        $status = PlanStatus::tryFrom($filter);
        $query = Plan::query();

        if ($status !== null) {
            $query->whereStatus($status);
        } elseif ($filter === self::STATUS_ALL) {
            $query->withTrashed();
        } else {
            $filter = '';
        }

        $plans = TableQuery::from($request)
            ->searchable(['name', 'code', 'description'])
            ->sortable(['name', 'price_per_till_monthly', 'price_per_till_yearly', 'trial_days', 'sort_order', 'created_at'])
            ->defaultSort('sort_order')
            ->paginate($query, fn (Plan $plan) => PlanData::row($plan));

        return Inertia::render('admin/plans/index', [
            'plans' => $plans,
            'filters' => ['status' => $filter === '' ? null : $filter],
            'counts' => $this->counts(),
            'toast' => $this->flashedToast($request),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/plans/create', [
            'features' => Feature::options(),
            'defaults' => [
                'trialDays' => Plan::DEFAULT_TRIAL_DAYS,
                'trialGraceDays' => Plan::DEFAULT_TRIAL_GRACE_DAYS,
                'graceDays' => Plan::DEFAULT_GRACE_DAYS,
                'sortOrder' => ((int) Plan::withTrashed()->max('sort_order')) + 10,
            ],
            'toast' => $this->flashedToast($request),
        ]);
    }

    public function store(StorePlanRequest $request, CreatePlan $createPlan): RedirectResponse
    {
        $plan = $createPlan->handle($request->toInput());

        $this->toast($request, "{$plan->name} plan created.");

        return redirect()->route('admin.plans.show', $plan);
    }

    public function show(Request $request, Plan $plan): Response
    {
        return Inertia::render('admin/plans/show', [
            'plan' => PlanData::fromModel($plan),
            'features' => Feature::options(),
            'activity' => PlanActivity::forPlan($plan),
            'toast' => $this->flashedToast($request),
        ]);
    }

    public function edit(Request $request, Plan $plan): Response
    {
        return Inertia::render('admin/plans/edit', [
            'plan' => PlanData::fromModel($plan),
            'features' => Feature::options(),
            'toast' => $this->flashedToast($request),
        ]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan, UpdatePlan $updatePlan): RedirectResponse
    {
        $updatePlan->handle($plan, $request->toInput());

        $this->toast($request, $plan->wasChanged() ? "{$plan->name} plan saved." : 'No changes to save.');

        return redirect()->route('admin.plans.show', $plan);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = ['current' => Plan::query()->count(), self::STATUS_ALL => Plan::withTrashed()->count()];

        foreach (PlanStatus::cases() as $status) {
            $counts[$status->value] = Plan::query()->whereStatus($status)->count();
        }

        return $counts;
    }
}
