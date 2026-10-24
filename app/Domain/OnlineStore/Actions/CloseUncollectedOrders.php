<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Ready click & collect orders nobody came for (audit OSA-21, `online-store:close-uncollected`, hourly): once
 * `online_store.not_collected_after_hours` (48; 0 = never) have passed since the end of the slot, the order is
 * marked "not collected" through ChangeOnlineOrderStatus (audited, can be undone for a few minutes like any move), so
 * the board's Ready column does not fill up for ever. Across every business. Returns how many were closed.
 */
final class CloseUncollectedOrders
{
    public function __construct(private readonly ChangeOnlineOrderStatus $change) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $hours = (int) config('online_store.not_collected_after_hours', 48);

        if ($hours <= 0) {
            return 0;
        }

        $cutoff = ($now ?? CarbonImmutable::now())->utc()->subHours($hours)->format('Y-m-d H:i:s');
        $count = 0;

        OnlineOrder::withoutCompanyScope()->where('status', OnlineOrderStatus::Ready->value)->where('fulfilment', FulfilmentMethod::Collection->value)
            ->where('collect_to', '<', $cutoff)->orderBy('collect_to')->limit(500)->get(['id', 'company_id'])
            ->groupBy('company_id')
            ->each(function ($orders, string $companyId) use (&$count): void {
                $company = Company::query()->find($companyId);

                foreach ($company === null ? [] : $orders as $order) {
                    try {
                        $this->change->handle($company, $order->id, OnlineOrderStatus::NotCollected);
                        $count++;
                    } catch (ValidationException) {
                        // Moved by someone in the meantime.
                    }
                }
            });

        return $count;
    }
}
