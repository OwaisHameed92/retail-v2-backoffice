<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

/**
 * The portal's ownership rule for head-office orders (HeadOfficeOrders, DECISIONS §10.6) in the handheld API's
 * words: a cancelled order → 409 PO_CANCELLED; the shop's own order, or one its till has taken on → 409
 * PO_OWNED_BY_TILL. Errors the head-office actions raise are renamed to the app's field names.
 */
final class HandheldOrderGuard
{
    private const FIELDS = [
        'supplierId' => 'supplier_id',
        'expectedDate' => 'expected_delivery_date',
        'notes' => 'notes',
        'status' => 'status',
        'shopId' => 'shop',
        'order' => 'order',
    ];

    /**
     * @throws ApiException
     */
    public static function assertPortalOwns(PurchaseOrder $order): void
    {
        if (HeadOfficeOrders::portalOwns($order)) {
            return;
        }

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw PdaErrors::orderCancelled();
        }

        throw PdaErrors::ownedByTill(HeadOfficeOrders::lockedReason($order) ?? 'The shop\'s till owns this order now. Create a new order instead.');
    }

    /** A head-office action's validation error, keyed as the app sends the fields (`lines.0` → `items.0`). */
    public static function rename(ValidationException $e): ValidationException
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $name = self::FIELDS[$key] ?? (string) preg_replace('/^lines(?=\.|$)/', 'items', $key);
            $errors[$name] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
