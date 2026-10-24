<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An online order (PlaceCollectOrder): click & collect or, from OS-9, local delivery; paid at the shop, on delivery
 * or by card online (DNA Payments). The till rings it up, so the order never writes till data. `number` is per
 * business (shown as W-0001); `token_hash` is the sha256 of the order page's secret link. `collect_from` /
 * `collect_to` are its slot: the collection time, or the delivery window. Slot and status times are UTC. Moved on by
 * ChangeOnlineOrderStatus; a card order is moved to new by RecordCardPayment once paid.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_id
 * @property string $online_store_shop_id
 * @property string $branch_id
 * @property int $number
 * @property OnlineOrderStatus $status
 * @property string $customer_name
 * @property string $customer_mobile
 * @property string $customer_email
 * @property CarbonImmutable $collect_from
 * @property CarbonImmutable $collect_to
 * @property string $items_total
 * @property FulfilmentMethod $fulfilment
 * @property OnlinePaymentMethod $payment_method
 * @property string|null $delivery_line1
 * @property string|null $delivery_line2
 * @property string|null $delivery_town
 * @property string|null $delivery_postcode
 * @property string|null $delivery_notes
 * @property string $delivery_fee
 * @property string|null $grand_total items + delivery; read through grandTotal()
 * @property string|null $payment_invoice_id the DNA invoice id of a card order
 * @property CarbonImmutable|null $payment_expires_at until when an unpaid card order holds its slot
 * @property CarbonImmutable|null $paid_at
 * @property string|null $paid_amount
 * @property string|null $payment_reference DNA's transaction id
 * @property string|null $payment_error
 * @property CarbonImmutable|null $out_for_delivery_at
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $customer_mobile_key the mobile as digits only (per-customer limits, audit OSA-1)
 * @property bool $cancelled_as_spam cancelled as a fake order: the customer is not emailed (OSA-1)
 * @property string|null $customer_note the customer's note to the shop (OSA-18)
 * @property bool $substitutions_ok the customer accepts a similar item for one that is out (OSA-18)
 * @property string|null $previous_status the stage before the last move, for undo (OSA-21)
 * @property CarbonImmutable|null $status_changed_at when staff last moved it (OSA-21)
 * @property CarbonImmutable|null $not_collected_at
 * @property CarbonImmutable|null $anonymised_at when the customer's details were removed (OSA-22)
 * @property string $token_hash
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $picked_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $collected_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read string $reference
 * @property-read Collection<int, OnlineOrderLine> $lines
 * @property-read OnlineStoreShop $shop
 * @property-read Branch $branch
 */
class OnlineOrder extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const PREFIX = 'W-';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @var array<string, mixed> S1 defaults: a new order is collected and paid at the shop unless set. */
    protected $attributes = ['fulfilment' => 'collection', 'payment_method' => 'payAtShop', 'delivery_fee' => '0.00'];

    /** "W-0001": the order number customers and staff see. */
    public static function referenceFor(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** "07700 900123" → "07700900123" (with a +44 / 0044 start read as 0): how mobiles are compared (OSA-1). */
    public static function mobileKey(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';
        $digits = preg_replace('/^(?:00)?44/', '0', $digits) ?? $digits;

        return mb_substr($digits, 0, 20);
    }

    /** The stored form of an order page token. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getReferenceAttribute(): string
    {
        return self::referenceFor($this->number);
    }

    /** What the customer pays: items plus delivery (rows before OS-9: the items). */
    public function grandTotal(): string
    {
        return $this->grand_total ?? $this->items_total;
    }

    public function isDelivery(): bool
    {
        return $this->fulfilment === FulfilmentMethod::Delivery;
    }

    /** Paid by card online (DNA confirmed it). */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** "12 High Street, Flat 2, Leeds, LS1 6AB", or null for a collection order. */
    public function deliveryAddress(): ?string
    {
        if (! $this->isDelivery()) {
            return null;
        }

        $parts = array_filter([$this->delivery_line1, $this->delivery_line2, $this->delivery_town, $this->delivery_postcode], fn (?string $p) => $p !== null && trim($p) !== '');

        return $parts === [] ? null : implode(', ', array_map('trim', $parts));
    }

    /**
     * @return HasMany<OnlineOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OnlineOrderLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<OnlineStoreShop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(OnlineStoreShop::class, 'online_store_shop_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OnlineOrderStatus::class,
            'collect_from' => 'immutable_datetime',
            'collect_to' => 'immutable_datetime',
            'items_total' => MoneyCast::class,
            'fulfilment' => FulfilmentMethod::class,
            'payment_method' => OnlinePaymentMethod::class,
            'delivery_fee' => MoneyCast::class,
            'grand_total' => MoneyCast::class,
            'paid_amount' => MoneyCast::class,
            'payment_expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'out_for_delivery_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'cancelled_as_spam' => 'boolean',
            'substitutions_ok' => 'boolean',
            'status_changed_at' => 'immutable_datetime',
            'not_collected_at' => 'immutable_datetime',
            'anonymised_at' => 'immutable_datetime',
            'picked_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
