<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldOrderReference;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/purchase-orders` with the app's optional `client_reference` (a UUID, unique per shop): a create that
 * repeats a reference already stored for the shop drafts nothing and answers the order made the first time (as it is
 * now: edited, sent or cancelled since), so a create retried after a lost reply is booked once.
 *
 * Race-safe: the reference row is inserted first, inside the same transaction as the order
 * (SaveHandheldPurchaseOrder), against the unique index `handheld_order_refs_shop_reference_unique`. A second request
 * with the same reference waits on that insert (MySQL row lock, SQLite write lock), then fails it and reads the
 * winner's order; a failed create rolls back its reference with it.
 */
final class CreateHandheldPurchaseOrder
{
    public function __construct(private readonly SaveHandheldPurchaseOrder $save) {}

    /**
     * @param  array<string, mixed>  $input  the order body (SaveHandheldPurchaseOrder)
     * @return array{0: PurchaseOrder, 1: bool} the order and whether this call created it
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Branch $shop, array $input, ?string $clientReference): array
    {
        if ($clientReference === null) {
            return [$this->save->handle($shop, $input), true];
        }

        $existing = self::existing($shop, $clientReference);

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            return [DB::transaction(function () use ($user, $shop, $input, $clientReference): PurchaseOrder {
                $reference = HandheldOrderReference::query()->create([
                    'branch_id' => $shop->id, 'client_reference' => $clientReference, 'handheld_user_id' => $user->id,
                ]);
                $order = $this->save->handle($shop, $input);
                $reference->forceFill(['purchase_order_id' => $order->id])->save();

                return $order;
            }), true];
        } catch (UniqueConstraintViolationException $e) {
            return [self::existing($shop, $clientReference) ?? throw $e, false];
        }
    }

    /** The shop's order already made with this reference, if any. */
    public static function existing(Branch $shop, string $clientReference): ?PurchaseOrder
    {
        $orderId = HandheldOrderReference::query()->where('branch_id', $shop->id)->where('client_reference', $clientReference)
            ->whereNotNull('purchase_order_id')->value('purchase_order_id');

        return $orderId === null ? null : PurchaseOrder::query()->where('branch_id', $shop->id)->find($orderId);
    }
}
