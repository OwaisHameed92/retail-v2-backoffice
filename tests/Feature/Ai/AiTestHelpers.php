<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Testing\FakeAiClient;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;

/**
 * Helpers for the module 5.1 tests. Use with `uses(AiTestHelpers::class)`. No test touches the network.
 */
trait AiTestHelpers
{
    public FakeAiClient $fake;

    public function installFakeAi(): FakeAiClient
    {
        config(['ai.enabled' => true, 'ai.anthropic.api_key' => '']);

        return $this->fake = FakeAiClient::install();
    }

    /**
     * @param  list<Feature>  $features
     */
    public function aiPlan(array $features = [Feature::AiAssistant, Feature::AiInsights], string $code = 'ai-plan'): Plan
    {
        return Plan::factory()->features($features)->create(['code' => $code]);
    }

    public function aiCompany(?Plan $plan = null, string $name = 'Khan Mini Mart', string $branchCode = 'MAIN', string $branchName = 'Main shop'): Company
    {
        $plan ??= $this->aiPlan(code: 'ai-plan-'.str()->lower(str()->random(6)));

        $company = Company::factory()->withBranch($branchCode, $branchName, 2)->create(['name' => $name]);
        $company->forceFill(['plan_id' => $plan->id])->save();

        return $company->refresh();
    }

    public function member(Company $company, CompanyRole $role = CompanyRole::Owner): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true]);

        return $user;
    }

    public function userContext(Company $company, CompanyRole $role = CompanyRole::Owner): AiContext
    {
        return AiContext::forUser($this->member($company, $role), $company);
    }

    public function branchOf(Company $company, ?string $code = null): Branch
    {
        $query = Branch::withoutCompanyScope()->whereBelongsTo($company);

        return ($code === null ? $query : $query->where('code', $code))->firstOrFail();
    }
}
