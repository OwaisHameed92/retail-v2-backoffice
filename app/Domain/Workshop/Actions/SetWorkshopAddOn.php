<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\Support\BranchAddOns;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Models\WorkshopConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The licence tick for the paid add-on `workshop_integration` on one shop (WORKSHOP-BIKEBOOK.md §6, §8). Stored in
 * `branches.licence_add_ons`; the shop's tills get it in their licence token's `features` at their next licence check
 * (the claims change, so a new token is signed). No price yet (owner, §10 Q10). Taking it off is refused while BikeBook
 * is connected or waiting to connect: disconnect first.
 */
final class SetWorkshopAddOn
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, bool $on, ?Model $actor = null): Branch
    {
        return DB::transaction(function () use ($branch, $on, $actor): Branch {
            $branch = Branch::withoutCompanyScope()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $has = BranchAddOns::has($branch, AddOn::WorkshopIntegration);

            if ($has === $on) {
                return $branch;
            }

            if (! $on && WorkshopConnection::withoutCompanyScope()->where('branch_id', $branch->id)->where('status', '<>', 'disconnected')->exists()) {
                throw ValidationException::withMessages(['workshop' => "Disconnect BikeBook from {$branch->name} first."]);
            }

            $values = array_map(fn (AddOn $a) => $a->value, BranchAddOns::of($branch));
            $values = $on ? [...$values, AddOn::WorkshopIntegration->value] : array_values(array_diff($values, [AddOn::WorkshopIntegration->value]));
            $branch->forceFill(['licence_add_ons' => $values === [] ? null : $values])->save();

            $this->audit->handle($on ? 'branch.licence_add_on_added' : 'branch.licence_add_on_removed', $branch,
                null, null, ['addOn' => AddOn::WorkshopIntegration->value, 'branch' => $branch->name], $actor);

            return $branch;
        });
    }
}
