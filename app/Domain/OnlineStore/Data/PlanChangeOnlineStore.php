<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PlanOnlineStore;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * OS-7: what Change plan does to the online store add-on (PlanChangePlanner; FollowPlanOnlineStore applies it). The
 * add-on follows the new plan: on when it includes the online store, off when it does not, also for an add-on that was
 * switched on by hand on the Billing tab. `billed` is the add-on's monthly price on the invoices after the change (on,
 * and a till has cloud sync), which the preview's new amount includes. Reads only.
 */
final readonly class PlanChangeOnlineStore
{
    public function __construct(
        public bool $before,
        public bool $after,
        public bool $byHand,
        public ?OnlineStore $store,
        public string $monthly,
        public ?string $billed,
        public bool $cloudSync,
        public ?string $blocked,
        public string $toName,
    ) {}

    /** Null where the country has no online stores (Pakistan). */
    public static function for(Company $company, ?Plan $from, Plan $to): ?self
    {
        if (! PlanOnlineStore::offered()) {
            return null;
        }

        $store = OnlineStoreAddOn::store($company);
        $before = $store !== null && $store->addon_on;
        $after = PlanOnlineStore::includes($to);
        $cloudSync = ($before || $after) && OnlineStoreAddOn::hasCloudSync($company);
        $monthly = Money::normalise(OnlineStoreSwitch::monthlyPrice($store?->monthly_price, $after ? $to : $from));

        return new self(
            before: $before,
            after: $after,
            byHand: $before && ! PlanOnlineStore::includes($from),
            store: $store,
            monthly: $monthly,
            billed: $after && $cloudSync ? $monthly : null,
            cloudSync: $cloudSync,
            blocked: $after && ! $before && ! OnlineStoreSwitch::enabled()
                ? "{$to->name} includes the online store, but online stores are switched off on this server (ONLINE_STORE). Choose another plan, or switch online stores on first."
                : null,
            toName: $to->name,
        );
    }

    /** The admin must choose the template: the add-on goes on and the business has no store yet. */
    public function needsTemplate(): bool
    {
        return $this->after && ! $this->before && $this->store === null;
    }

    /**
     * The preview's "Online store" part, or null when the add-on is off before and after.
     *
     * @return array{change: string, title: string, lines: list<string>, needsTemplate: bool, templates: list<array{value: string, label: string}>}|null
     */
    public function toArray(BillingCycle $cycle, string $vatRate): ?array
    {
        if (! $this->before && ! $this->after) {
            return null;
        }

        return [
            'change' => match (true) {
                $this->before && $this->after => 'stays',
                $this->after => 'on',
                default => 'off',
            },
            'title' => 'Online store',
            'lines' => $this->lines($cycle, $vatRate),
            'needsTemplate' => $this->needsTemplate(),
            'templates' => OnlineStoreAddOnInput::templateOptions(),
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(BillingCycle $cycle, string $vatRate): array
    {
        $tax = app(Country::class)->taxName();
        $price = BillingFormat::money($this->monthly).' a month before '.$tax;
        $line = InvoiceMaths::line('1', OnlineStoreAddOn::cyclePrice($this->monthly, $cycle), $vatRate)['gross'];
        $inAmount = 'Included in the new amount above: Online store '.BillingFormat::money($line).' '.$cycle->per().(Money::isZero($vatRate) ? '.' : " with {$tax}.");
        $own = $this->store?->monthly_price !== null ? ' (the business’s own price)' : '';

        if ($this->before && ! $this->after) {
            $off = ["Switched off: {$this->toName} does not include the online store. The store goes offline and its “Online store” line ({$price}) stops from the next invoice. Its settings are kept."];

            if ($this->byHand) {
                $off[] = 'The add-on was switched on by hand on the Billing tab. Changing plan follows the new plan; switch it on again on the Billing tab afterwards if this business should keep it.';
            }

            return $off;
        }

        $lines = $this->before
            ? [
                $this->byHand
                    ? "Stays on: {$this->toName} includes the online store, so the add-on switched on by hand now follows the plan."
                    : "Stays on: {$this->toName} includes the online store.",
                "{$price}{$own}, once per business whatever its branches and tills: its own line “Online store” on the same invoice, collected by the same Direct Debit.",
            ]
            : [
                "Switched on: {$this->toName} includes the online store. ".($this->store === null
                    ? 'The store is made with the template chosen below: not live and not taking orders until the business publishes it.'
                    : "Its store /{$this->store->slug} is switched back on as it was set up ({$this->store->template->label()})."),
                "{$price}{$own}, once per business whatever its branches and tills: its own line “Online store” on every invoice raised from now on, collected by the same Direct Debit.",
            ];

        $lines[] = $this->billed !== null
            ? $inAmount
            : 'Not charged until one of its tills has cloud sync (the online dashboard): the store needs it.';

        return $lines;
    }
}
