<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderStatusChanged;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one of the business's online orders on (portal → Online orders, `store.manage`): new → picking → ready →
 * collected, or cancelled with a reason from any open stage (OnlineOrderStatus::next). Sets the stage's time
 * column (UTC). The row is locked, so two people moving the same order cannot both win. Audited;
 * OnlineOrderStatusChanged is dispatched after commit (OS-4 emails the customer).
 *
 *     app(ChangeOnlineOrderStatus::class)->handle($company, $orderId, OnlineOrderStatus::Cancelled, 'Out of stock');
 */
final class ChangeOnlineOrderStatus
{
    public const REASON_MAX = 255;

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $orderId, OnlineOrderStatus $to, ?string $reason = null): OnlineOrder
    {
        $reason = trim((string) $reason);

        if ($to === OnlineOrderStatus::Cancelled && mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why the order is cancelled; the customer is told.']);
        }

        if (mb_strlen($reason) > self::REASON_MAX) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason to '.self::REASON_MAX.' characters.']);
        }

        [$order, $from] = $this->tenancy->runAs($company, fn (): array => DB::transaction(function () use ($company, $orderId, $to, $reason): array {
            $order = OnlineOrder::query()->where('company_id', $company->id)->whereKey($orderId)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['order' => 'That order was not found.']);
            $from = $order->status;

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages(['status' => "A {$from->label()} order cannot be moved to {$to->label()}."]);
            }

            $column = $to->timeColumn();
            $order->forceFill([
                'status' => $to,
                ...($column !== null ? [$column => CarbonImmutable::now('UTC')] : []),
                ...($to === OnlineOrderStatus::Cancelled ? ['cancel_reason' => $reason] : []),
            ])->save();

            $this->audit->handle('online_order.status_changed', $order, ['status' => $from->value], array_filter([
                'status' => $to->value, 'cancel_reason' => $to === OnlineOrderStatus::Cancelled ? $reason : null,
            ]), ['number' => $order->reference], companyId: $company->id);

            return [$order, $from];
        }));

        OnlineOrderStatusChanged::dispatch($order, $from);

        return $order;
    }
}
