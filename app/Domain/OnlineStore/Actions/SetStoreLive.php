<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Legal\StoreTrader;
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
 * available on this instance (OnlineStoreSwitch), at least one shop customers can collect from, and (OS-6) the
 * business's add-on and cloud sync (OnlineStoreSwitch::availableFor), and (OS-11, audit OSA-2) the trader details
 * saved on the Legal & contact tab (StoreTrader::missing). Taking it off
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

            // OS-6: the add-on switched on by Switch & Save, and a till with cloud sync.
            if ($live && ! OnlineStoreSwitch::availableFor($company, $store)) {
                throw ValidationException::withMessages(['store' => 'The online store add-on is not on for this business, or none of its tills has cloud sync. Contact Switch & Save.']);
            }

            // OS-11 (audit OSA-2): the trader details UK law asks an online shop to show, saved on "Legal & contact".
            if ($live && ($missing = StoreTrader::missing($store)) !== []) {
                throw ValidationException::withMessages(['store' => self::legalMissing($missing)]);
            }

            $store->forceFill(['is_live' => $live, 'live_at' => $live ? CarbonImmutable::now('UTC') : $store->live_at])->save();
            $this->audit->handle($live ? 'online_store.published' : 'online_store.unpublished', $store, ['is_live' => ! $live], ['is_live' => $live], companyId: $company->id);

            return $store;
        });
    }

    /**
     * @param  list<string>  $missing
     */
    public static function legalMissing(array $missing): string
    {
        $last = array_pop($missing);
        $list = $missing === [] ? $last : implode(', ', $missing).' and '.$last;

        return "Add your {$list} on the Legal & contact tab before you publish the store.";
    }
}
