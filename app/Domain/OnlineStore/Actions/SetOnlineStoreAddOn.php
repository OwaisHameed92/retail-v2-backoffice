<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\Billing\GoCardless\Actions\StartMandateDeadline;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\AddOnStoreSlug;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Admin → business → Billing → "Online store add-on" (OS-6, `billing.manage`): switches the add-on on or off for one
 * business, sets its monthly price (blank = the default £20, before VAT) and the store's template (owner 2026-10-24:
 * we choose it, Fresh Market or Butcher's Block). Switching on makes the business's store row when it has none
 * (address from the business name, not live, not taking orders); the template can be changed later. Audited
 * `online_store.addon_updated` (and `online_store.created`). Nothing changes for a business until this is used.
 *
 * Switching on needs online stores on this server (`ONLINE_STORE`), a monthly (recurring) plan (owner 2026-10-24: not
 * for setup-only businesses) and cloud sync on one of the business's tills. Switching off closes the store (its
 * settings are kept).
 *
 * Billing (owner 2026-10-24) follows UpdateCompanyPricing, a price change: the add-on is a line "Online store" on the
 * business's period invoice (CompanyPricing / InvoiceLineBuilder), on every invoice raised from now on, and a live
 * Direct Debit subscription changes its amount from the next payment (SyncSubscription). Nothing is prorated or
 * invoiced for the current period. Off (or a new price): from the next invoice and payment. A business waiting for its
 * first mandate whose amount goes from £0 to more gets a fresh Direct Debit deadline (StartMandateDeadline), as for
 * pricing. No rule of its own for non-payment: the period invoice's rules apply.
 *
 * OS-7: a plan can include the add-on (FollowPlanOnlineStore calls this with `planCode`): on such a call the plan's
 * terms replace the monthly-plan and cloud-sync checks (the line is billed only while a till has cloud sync, as
 * always), the audit names the plan, and the caller syncs the Direct Debit (no second sync here).
 *
 * @phpstan-type Result array{store: OnlineStore, warning: string|null}
 */
class SetOnlineStoreAddOn
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly StartMandateDeadline $deadlines,
        private readonly SyncSubscription $syncSubscription,
    ) {}

    /**
     * @return Result
     *
     * @throws ValidationException
     */
    public function handle(Company $company, OnlineStoreAddOnInput $input): array
    {
        $this->check($company, $input);
        $now = CarbonImmutable::now();
        $recurringBefore = $this->deadlines->before($company);
        $changed = false;

        $store = DB::transaction(function () use ($company, $input, $now, &$changed) {
            $store = OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->lockForUpdate()->first();
            $old = $store === null ? null : self::snapshot($store);

            if ($store === null && ! $input->on) {
                throw ValidationException::withMessages(['on' => "{$company->name} does not have the online store add-on."]);
            }

            if ($store === null) {
                $store = new OnlineStore(['slug' => AddOnStoreSlug::suggest($company), 'is_live' => false, 'taking_orders' => false]);
                $store->company_id = $company->id;
            }

            $switchedOn = $input->on && ! ($old['addon_on'] ?? false);
            $store->addon_on = $input->on;
            $store->addon_on_at = $switchedOn ? $now : $store->addon_on_at;
            $store->monthly_price = $input->monthlyPrice === null ? null : Money::normalise($input->monthlyPrice);
            $store->template = $input->template ?? $store->template;

            $store->save();

            if ($old === null) {
                $this->audit->handle('online_store.created', $store, null, ['slug' => $store->slug, 'template' => $store->template->value], companyId: $company->id);
            }

            $new = self::snapshot($store);

            if ($old !== $new) {
                $changed = true;
                $this->audit->handle('online_store.addon_updated', $store, $old, $new, $input->byPlan() ? ['plan' => $input->planCode] : [], companyId: $company->id);
            }

            return $store;
        });

        return ['store' => $store->refresh(), 'warning' => $changed && ! $input->byPlan() ? $this->billing($company, $recurringBefore) : null];
    }

    /** As UpdateCompanyPricing: a fresh deadline when the amount starts to recur, then the Direct Debit amount. */
    private function billing(Company $company, ?string $recurringBefore): ?string
    {
        $this->deadlines->after($company, $recurringBefore, 'onlineStore');

        try {
            $this->syncSubscription->handle($company->refresh(), 'onlineStore');
        } catch (GoCardlessException $exception) {
            // The daily reconcile corrects the amount; staff can also press "Update subscription".
            Log::warning('Direct Debit amount not updated after the online store add-on changed', ['company_id' => $company->id, 'error' => $exception->getMessage()]);

            return 'GoCardless could not be updated just now. The daily reconcile puts the Direct Debit amount right, or use Sync on the Direct Debit card.';
        }

        return null;
    }

    /** @throws ValidationException */
    private function check(Company $company, OnlineStoreAddOnInput $input): void
    {
        $company->refresh();

        if ($company->trashed() || $company->isCancelled()) {
            throw ValidationException::withMessages(['on' => "{$company->name} is cancelled."]);
        }

        if (! app(Country::class)->feature(OnlineStoreSwitch::FEATURE)) {
            throw ValidationException::withMessages(['on' => 'Online stores are not offered in this country.']);
        }

        if ($input->monthlyPrice !== null && Money::isNegative($input->monthlyPrice)) {
            throw ValidationException::withMessages(['monthly_price' => 'The price cannot be below 0.']);
        }

        if (! $input->on) {
            return;
        }

        if ($input->template === null && ! OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->exists()) {
            throw ValidationException::withMessages(['template' => 'Choose the store’s template.']);
        }

        // OS-7: a plan that includes it is a recurring plan; it is billed once a till has cloud sync (no check below).
        if (! $input->byPlan() && ! OnlineStoreAddOn::onRecurringPlan($company)) {
            throw ValidationException::withMessages(['on' => "The online store add-on is only for businesses on a monthly plan. Move {$company->name} to a monthly plan first (Change plan)."]);
        }

        if (! OnlineStoreSwitch::enabled()) {
            throw ValidationException::withMessages(['on' => 'Online stores are switched off on this server (ONLINE_STORE), so the add-on cannot be switched on yet.']);
        }

        if ($input->byPlan()) {
            return;
        }

        // OS-13: a business selling without tills (online store only) needs no cloud sync: its products are the portal's.
        if (! OnlineStoreAddOn::hasCatalogue($company)) {
            throw ValidationException::withMessages(['on' => "{$company->name} has no till with cloud sync (the online dashboard). The store needs it: its products come from the tills."]);
        }
    }

    /**
     * @return array{addon_on: bool, monthly_price: string|null, template: string}
     */
    private static function snapshot(OnlineStore $store): array
    {
        return ['addon_on' => $store->addon_on, 'monthly_price' => $store->monthly_price, 'template' => $store->template->value];
    }
}
