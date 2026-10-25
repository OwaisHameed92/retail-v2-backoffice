<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop of the online store (SaveStoreShop): customers collect from it (`enabled`), it delivers locally
 * (`delivery_enabled`, OS-9, SaveDeliverySettings), or both. OS-12: a shop takes part in the public store when either
 * is on (ShopMethods); a delivery-only shop has collection off. `opening_hours` is keyed by weekday (`monday`…`sunday`, shop
 * time): `{"open": "08:00", "close": "20:00"}`, or null / missing when it does not hand out orders that day.
 * Collection slots (CollectionSlots) run from opening to closing, `slot_minutes` long, at most `orders_per_slot`
 * orders each, the first one at least `prep_minutes` from now.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_id
 * @property string $branch_id
 * @property array<string, mixed>|null $opening_hours weekday => {open, close} or null (read through hoursOn())
 * @property int $prep_minutes
 * @property int $slot_minutes
 * @property int $orders_per_slot
 * @property bool $enabled collection is on: customers can collect here (read through collects())
 * @property bool $delivery_enabled OS-9: the shop delivers locally (SaveDeliverySettings)
 * @property list<string>|null $delivery_areas postcode districts / sectors it delivers to ("B23", "B24 9")
 * @property string $delivery_fee
 * @property string|null $delivery_free_over items total from which delivery is free
 * @property string|null $delivery_minimum the smallest items total it delivers
 * @property array<string, mixed>|null $delivery_hours weekday => {open, close} or null, like opening_hours
 * @property int $delivery_slot_minutes
 * @property int $delivery_orders_per_slot
 * @property int $delivery_lead_minutes the first delivery slot starts at least this long after the order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OnlineStore $store
 * @property-read Branch $branch
 */
class OnlineStoreShop extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<OnlineStore, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(OnlineStore::class, 'online_store_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The hours of one weekday ("monday"…), or null when closed for collections.
     *
     * @return array{open: string, close: string}|null
     */
    public function hoursOn(string $weekday): ?array
    {
        $hours = $this->opening_hours[strtolower($weekday)] ?? null;

        return is_array($hours) && isset($hours['open'], $hours['close']) ? ['open' => (string) $hours['open'], 'close' => (string) $hours['close']] : null;
    }

    /**
     * OS-9: the delivery hours of one weekday, or null when it does not deliver that day.
     *
     * @return array{open: string, close: string}|null
     */
    public function deliveryHoursOn(string $weekday): ?array
    {
        $hours = $this->delivery_hours[strtolower($weekday)] ?? null;

        return is_array($hours) && isset($hours['open'], $hours['close']) ? ['open' => (string) $hours['open'], 'close' => (string) $hours['close']] : null;
    }

    /** Customers can collect from this shop (OS-12: `enabled` means collection is on). */
    public function collects(): bool
    {
        return (bool) $this->enabled;
    }

    /** OS-9: it delivers and has an area to deliver to. */
    public function delivers(): bool
    {
        return $this->delivery_enabled && ($this->delivery_areas ?? []) !== [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_hours' => 'array',
            'prep_minutes' => 'integer',
            'slot_minutes' => 'integer',
            'orders_per_slot' => 'integer',
            'enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'delivery_areas' => 'array',
            'delivery_fee' => MoneyCast::class,
            'delivery_free_over' => MoneyCast::class,
            'delivery_minimum' => MoneyCast::class,
            'delivery_hours' => 'array',
            'delivery_slot_minutes' => 'integer',
            'delivery_orders_per_slot' => 'integer',
            'delivery_lead_minutes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
