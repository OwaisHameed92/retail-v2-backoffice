<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Runs public online store work scoped to the store's own business (docs/online-store.md "Security"): a public
 * request has no signed-in user, so the business comes from the store found by its slug, never from an ambient
 * scope. Inside, every tenant model query is filtered to that business (and the queries filter on it explicitly too).
 */
final class StoreScope
{
    /**
     * @template T
     *
     * @param  Closure(Company): T  $callback
     * @return T
     */
    public static function run(OnlineStore $store, Closure $callback): mixed
    {
        return app(CurrentCompany::class)->runAs(self::company($store), $callback);
    }

    /**
     * The business's own store, for portal actions running scoped to it (CurrentCompany::runAs).
     *
     * @throws ValidationException when it has none yet
     */
    public static function storeOf(Company $company): OnlineStore
    {
        return OnlineStore::query()->where('company_id', $company->id)->first()
            ?? throw ValidationException::withMessages(['store' => 'Set up the online store first.']);
    }

    public static function company(OnlineStore $store): Company
    {
        $company = $store->relationLoaded('company') ? $store->getRelation('company') : null;

        return $company instanceof Company && $company->id === $store->company_id ? $company : Company::query()->findOrFail($store->company_id);
    }
}
