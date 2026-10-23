<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
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
 * A click & collect order (PlaceCollectOrder), paid at the shop: the till rings it up at collection, so the order
 * never writes till data. `number` is per business (shown as W-0001); `token_hash` is the sha256 of the order
 * page's secret link. Slot and status times are UTC. Moved on by ChangeOnlineOrderStatus.
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

    /** "W-0001": the order number customers and staff see. */
    public static function referenceFor(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
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
            'picked_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
