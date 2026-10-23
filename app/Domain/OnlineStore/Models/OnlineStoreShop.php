<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop customers can collect from (SaveStoreShop). `opening_hours` is keyed by weekday (`monday`…`sunday`, shop
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
 * @property bool $enabled
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
