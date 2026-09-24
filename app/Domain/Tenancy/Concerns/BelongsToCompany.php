<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Exceptions\CompanyMismatch;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-owned. The table must have a `company_id` column.
 *
 * - Every query is filtered to CurrentCompany via CompanyScope. With no current company the query throws
 *   (fail closed), in web requests, jobs and console alike.
 * - On create, `company_id` is filled from CurrentCompany. Writing a row for another company throws.
 *   With no current company, create only works when `company_id` is given explicitly (admin/sync code).
 * - Changing `company_id` of an existing row while a company is current throws.
 *
 * Escape hatch: `Model::withoutCompanyScope()` returns a query across ALL companies. It is for admin
 * (super admin area) and sync code only; never use it in tenant portal code. Jobs and console commands
 * should prefer `app(CurrentCompany::class)->runAs($company, fn () => ...)`.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            $currentId = app(CurrentCompany::class)->id();
            $given = $model->getAttribute('company_id');

            if ($currentId === null) {
                if ($given === null) {
                    throw MissingCurrentCompany::forCreate($model::class);
                }

                return;
            }

            if ($given !== null && (string) $given !== $currentId) {
                throw CompanyMismatch::forModel($model::class);
            }

            $model->setAttribute('company_id', $currentId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id') && app(CurrentCompany::class)->has()) {
                throw CompanyMismatch::forModel($model::class);
            }
        });
    }

    /**
     * Query across all companies. ADMIN / SYNC CODE ONLY. Never use in tenant portal code.
     *
     * @return Builder<static>
     */
    public static function withoutCompanyScope(): Builder
    {
        return static::query()->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
