<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant model to the current company. Fails closed: with no current company
 * the query throws instead of returning every tenant's rows.
 */
class CompanyScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = app(CurrentCompany::class)->id();

        if ($companyId === null) {
            throw MissingCurrentCompany::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn('company_id'), $companyId);
    }
}
