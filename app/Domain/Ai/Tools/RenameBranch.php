<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiWriteTool;
use App\Domain\Ai\Data\AiProposal;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\Actions\UpdateBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;

/**
 * Write: rename a branch of the user's business. Proposes only; after confirmation it calls the Tenancy module's
 * UpdateBranch action (same validation, transaction and `branch.updated` audit as the admin screen).
 */
final class RenameBranch implements AiWriteTool
{
    public function __construct(private readonly UpdateBranch $updateBranch) {}

    public function name(): string
    {
        return 'rename_branch';
    }

    public function description(): string
    {
        return 'Propose renaming one branch (shop) of the user\'s business. This does not change anything: it '
            .'creates a proposal the user must confirm in the app. Get the branch id from get_company_overview first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'branch_id' => ['type' => 'string', 'description' => 'The branch id (26 characters) from get_company_overview.'],
                'new_name' => ['type' => 'string', 'description' => 'The new branch name, at most 120 characters.'],
            ],
            'required' => ['branch_id', 'new_name'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'string', new ValidUlid],
            'new_name' => ['required', 'string', 'max:120', 'not_regex:/^\s*$/'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::SettingsManage;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function audience(): ToolAudience
    {
        return ToolAudience::Tenant;
    }

    public function handle(array $input, AiContext $context): array|AiProposal
    {
        $branch = Branch::query()->findOrFail($input['branch_id']);
        $newName = trim((string) $input['new_name']);

        if ($newName === $branch->name) {
            return ['status' => 'noChange', 'note' => "The branch is already called \"{$branch->name}\"."];
        }

        return new AiProposal(
            preview: "Rename branch \"{$branch->name}\" ({$branch->code}) to \"{$newName}\".",
            input: ['branch_id' => $branch->id, 'new_name' => $newName],
        );
    }

    public function execute(array $input, AiContext $context): array
    {
        $branch = Branch::query()->findOrFail($input['branch_id']);

        $branch = $this->updateBranch->handle($branch, new BranchDetails(
            code: $branch->code,
            name: trim((string) $input['new_name']),
            nation: $branch->nation,
            address: $branch->address,
            phone: $branch->phone,
            vatNumber: $branch->vat_number,
            licensedHoursJson: $branch->licensed_hours_json,
            isDrsReturnPoint: $branch->is_drs_return_point,
            areaM2: $branch->area_m2,
        ));

        return ['branchId' => $branch->id, 'code' => $branch->code, 'name' => $branch->name];
    }
}
