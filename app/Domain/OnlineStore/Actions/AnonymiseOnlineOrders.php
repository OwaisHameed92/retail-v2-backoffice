<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use Carbon\CarbonImmutable;

/**
 * Removes customers' personal details from finished online orders (audit OSA-22, UK GDPR storage limitation;
 * `online-store:anonymise`, daily): `online_store.anonymise_after_months` (24; 0 = never) after the order's slot, an
 * order that is no longer open keeps its number, shop, lines and totals (for the shop's figures) but loses the name,
 * mobile, email, delivery address and notes. Its order link stops working long before (StoreLinks). Across every
 * business, in batches. Returns how many were anonymised.
 */
final class AnonymiseOnlineOrders
{
    public const NAME = 'Removed';

    public function handle(?CarbonImmutable $now = null): int
    {
        $months = (int) config('online_store.anonymise_after_months', 24);

        if ($months <= 0) {
            return 0;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $open = array_values(array_map(fn (OnlineOrderStatus $s) => $s->value, array_filter(OnlineOrderStatus::cases(), fn (OnlineOrderStatus $s) => $s->isOpen())));

        return OnlineOrder::withoutCompanyScope()->whereNull('anonymised_at')->whereNotIn('status', [...$open, OnlineOrderStatus::AwaitingPayment->value])
            ->where('collect_to', '<', $now->subMonths($months)->format('Y-m-d H:i:s'))
            ->limit(1000)->update([
                'customer_name' => self::NAME, 'customer_mobile' => '', 'customer_email' => '', 'customer_mobile_key' => null,
                'delivery_line1' => null, 'delivery_line2' => null, 'delivery_town' => null, 'delivery_postcode' => null,
                'delivery_notes' => null, 'customer_note' => null, 'anonymised_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
    }
}
