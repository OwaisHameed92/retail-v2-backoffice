<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Publishes the store (its address starts answering) or takes it off line. Publishing needs the online store to be
 * available on this instance (OnlineStoreSwitch) and at least one shop customers can collect from. Taking it off
 * line always works. Nothing is written when nothing changes. Audited.
 */
final class SetStoreLive
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly CurrentCompany $tenancy,
        private readonly StorefrontShops $shops,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, bool $live): OnlineStore
    {
        return $this->tenancy->runAs($company, function () use ($company, $live): OnlineStore {
            $store = StoreScope::storeOf($company);

            if ($store->is_live === $live) {
                return $store;
            }

            if ($live && ! OnlineStoreSwitch::enabled()) {
                throw ValidationException::withMessages(['store' => 'The online store is not available yet.']);
            }

            if ($live && $this->shops->handle($store->setRelation('company', $company))->isEmpty()) {
                throw ValidationException::withMessages(['store' => 'Add at least one shop customers can collect from before you publish the store.']);
            }

            $store->forceFill(['is_live' => $live, 'live_at' => $live ? CarbonImmutable::now('UTC') : $store->live_at])->save();
            $this->audit->handle($live ? 'online_store.published' : 'online_store.unpublished', $store, ['is_live' => ! $live], ['is_live' => $live], companyId: $company->id);

            return $store;
        });
    }
}
