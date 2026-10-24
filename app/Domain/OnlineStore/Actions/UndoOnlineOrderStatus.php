<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back the last move of an online order (audit OSA-21: a mistaken tap on "Collected" or "Cancel"): within
 * `online_store.undo_minutes` (10) of the move, the order goes back to the stage it came from and the time of the
 * stage it left is cleared (a cancel also loses its reason). Only one step back. An email the move already sent is
 * not taken back. Row locked; audited.
 */
final class UndoOnlineOrderStatus
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $orderId, ?CarbonImmutable $now = null): OnlineOrder
    {
        $now = ($now ?? CarbonImmutable::now())->utc();

        return $this->tenancy->runAs($company, fn (): OnlineOrder => DB::transaction(function () use ($company, $orderId, $now): OnlineOrder {
            $order = OnlineOrder::query()->where('company_id', $company->id)->whereKey($orderId)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['order' => 'That order was not found.']);
            $back = OnlineOrderStatus::tryFrom((string) $order->previous_status);

            if (! self::canUndo($order, $now) || $back === null) {
                throw ValidationException::withMessages(['status' => 'This move can no longer be undone.']);
            }

            $from = $order->status;
            $column = $from->timeColumn();
            $order->forceFill([
                'status' => $back,
                'previous_status' => null,
                'status_changed_at' => null,
                ...($column !== null ? [$column => null] : []),
                ...($from === OnlineOrderStatus::Cancelled ? ['cancel_reason' => null, 'cancelled_as_spam' => false] : []),
            ])->save();

            $this->audit->handle('online_order.status_undone', $order, ['status' => $from->value], ['status' => $back->value],
                ['number' => $order->reference], companyId: $company->id);

            return $order;
        }));
    }

    /** The order's last move can still be taken back. */
    public static function canUndo(OnlineOrder $order, CarbonImmutable $now): bool
    {
        return $order->previous_status !== null && $order->status_changed_at !== null && ! $order->status->isPaymentPending()
            && $order->status_changed_at->greaterThan($now->subMinutes(max(1, (int) config('online_store.undo_minutes', 10))));
    }
}
