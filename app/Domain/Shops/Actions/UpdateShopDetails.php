<?php

namespace App\Domain\Shops\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shops\Data\ShopDetails;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A business edits one of its shops from the portal (module 4.7). Only ShopDetails::COLUMNS are written; a change to
 * one of the till's Branch members queues the row for that shop's till (SentToTills). A one-shop user may edit only
 * their own shop. Audited as `branch.updated` (via portal); a save that changes nothing writes nothing.
 */
class UpdateShopDetails
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws AuthorizationException when a one-shop user edits another shop
     * @throws ValidationException
     */
    public function handle(Branch $branch, ShopDetails $details): Branch
    {
        $company = $this->tenancy->require();
        $restricted = $this->tenancy->restrictedBranchId();

        if ($branch->company_id !== $company->id || ($restricted !== null && $restricted !== $branch->id)) {
            throw new AuthorizationException('You can only change your own shop.');
        }

        if ($details->name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the shop name.']);
        }

        return DB::transaction(function () use ($branch, $details) {
            $branch->fill(array_intersect_key($details->toAttributes(), array_flip(ShopDetails::COLUMNS)));

            [$before, $after] = AuditChanges::of($branch);

            if ($after === []) {
                return $branch;
            }

            $branch->save();

            $this->audit->handle('branch.updated', $branch, $before, $after, ['via' => 'portal']);

            return $branch;
        });
    }
}
