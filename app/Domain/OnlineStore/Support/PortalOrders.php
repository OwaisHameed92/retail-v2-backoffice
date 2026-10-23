<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The online orders the signed-in portal user may see (portal → Online orders): the current business's (company
 * scope plus an explicit `company_id` filter), and only their own shop's for a one-shop user. Anything else is a 404,
 * so another business's order id tells nothing.
 */
final class PortalOrders
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return Builder<OnlineOrder>
     */
    public function query(): Builder
    {
        $company = $this->tenancy->require();

        return OnlineOrder::query()->where('online_orders.company_id', $company->id)
            ->when($this->tenancy->restrictedBranchId(), fn (Builder $query, string $branchId) => $query->where('online_orders.branch_id', $branchId));
    }

    /**
     * @throws NotFoundHttpException
     */
    public function find(string $id): OnlineOrder
    {
        return $this->query()->whereKey($id)->first() ?? throw new NotFoundHttpException;
    }
}
