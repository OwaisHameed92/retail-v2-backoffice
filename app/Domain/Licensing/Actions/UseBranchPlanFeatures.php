<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Validation\ValidationException;

/**
 * "Use the plan's features": the branch's own feature list is dropped (`licence_features` null), so it follows its
 * plan again. Saved like any other licence-setting change (UpdateBranchLicence): the plan's features are copied
 * onto every live key, each till gets its new token at its next check-in, and `branch.licence_updated` is
 * recorded. Nothing happens when the branch already follows the plan.
 */
class UseBranchPlanFeatures
{
    public function __construct(private readonly UpdateBranchLicence $update) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch): Branch
    {
        return $this->update->handle($branch, BranchLicenceSettings::of($branch)->withFeatures(null));
    }
}
