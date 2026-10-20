<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Support\HandheldOrderGuard;
use App\Domain\Purchasing\Actions\ChangeHeadOfficeOrderStatus;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/purchase-orders/{id}/cancel`: cancels a head-office order of the shop while the portal still owns it,
 * through the portal's own action (ChangeHeadOfficeOrderStatus), so the shop's till pulls the cancellation. Once the
 * till owns the order, cancel it on the till (409 PO_OWNED_BY_TILL); a cancelled order → 409 PO_CANCELLED.
 */
final class CancelHandheldPurchaseOrder
{
    public function __construct(private readonly ChangeHeadOfficeOrderStatus $change) {}

    /**
     * @throws ApiException|ValidationException
     */
    public function handle(PurchaseOrder $order, ?string $reason = null): PurchaseOrder
    {
        HandheldOrderGuard::assertPortalOwns($order);

        try {
            return $this->change->handle($order, PurchaseOrderStatus::Cancelled, $reason);
        } catch (ValidationException $e) {
            throw HandheldOrderGuard::rename($e);
        }
    }
}
