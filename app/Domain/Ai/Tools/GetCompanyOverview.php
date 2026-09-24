<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Ai\Support\AiPlan;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Support\Facades\Schema;

/**
 * Read: the current business, its branches and tills, its plan and (when module 1.3 is present) licence counts.
 * Takes no input: it can only ever describe the actor's own company. No personal data (addresses, phones, emails).
 */
final class GetCompanyOverview implements AiTool
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function name(): string
    {
        return 'get_company_overview';
    }

    public function description(): string
    {
        return 'Get an overview of the user\'s business: status, plan and included features, every branch (shop) '
            .'with its code, id and tills, and licence counts by status. Use it for questions about branches, tills, '
            .'the plan or licences, and to find a branch id before proposing a change to a branch.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function requiredAbility(): Ability
    {
        return Ability::DashboardView;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function audience(): ToolAudience
    {
        return ToolAudience::Tenant;
    }

    public function handle(array $input, AiContext $context): array
    {
        $company = $this->tenancy->require();
        $plan = AiPlan::for($company);

        $tills = Register::query()->orderBy('code')->get()->groupBy('branch_id');

        $branches = Branch::query()->orderBy('code')->get()->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'nation' => $branch->nation->value,
            'isActive' => $branch->is_active,
            'tills' => $tills->get($branch->id, collect())->map(fn (Register $till) => [
                'id' => $till->id,
                'code' => $till->code,
                'name' => $till->name,
                'isMainTill' => $till->is_main_till,
                'isActive' => $till->is_active,
            ])->values()->all(),
        ])->values();

        return [
            'company' => [
                'name' => $company->name,
                'status' => $company->status->value,
                'trialEndsAt' => $company->trial_ends_at?->toDateString(),
            ],
            'plan' => $plan === null ? null : [
                'name' => $plan->name,
                'features' => $plan->features->map(fn (Feature $feature) => $feature->label())->values()->all(),
            ],
            'counts' => [
                'branches' => $branches->count(),
                'activeBranches' => $branches->where('isActive', true)->count(),
                'tills' => $tills->flatten()->count(),
                'activeTills' => $tills->flatten()->where('is_active', true)->count(),
            ],
            'branches' => $branches->all(),
            'licences' => $this->licenceCounts(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function licenceCounts(): array
    {
        if (! class_exists(Licence::class) || ! Schema::hasTable('licences')) {
            return ['available' => false, 'note' => 'Licence data is not available yet.'];
        }

        /** @var array<string, int> $byStatus */
        $byStatus = Licence::query()->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count) => (int) $count)
            ->all();

        ksort($byStatus);

        return ['available' => true, 'total' => array_sum($byStatus), 'byStatus' => $byStatus];
    }
}
