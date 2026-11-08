<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A BikeBook sales order ("Send to POS", WORKSHOP-BIKEBOOK.md §3.6), hub-owned: it goes to the connected shop's till
 * as the pull row `WorkshopOrder` (WorkshopOrderFeed, contract proposal workshop-order). Our ULID is the SalesOrder id
 * BikeBook keeps, the pull row's id and the id of the till's CustomerOrder made from it.
 *
 * `version` rises by one on every change (header, lines, cancel, "Complete" from BikeBook); the same content again
 * changes nothing (`content_hash`). `hub_version` is the pull feed's version (null = waiting to be stamped).
 * `locked_at`: the till has taken money on it, so changes are refused (409 order_locked). `reported_*`: what the last
 * `order.statuschanged` told BikeBook (ReportWorkshopOrders compares, so each change is told once).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $connection_id
 * @property string $order_number
 * @property string $reference
 * @property WorkshopOrderStatus $status
 * @property string $external_job_number
 * @property string $external_job_id
 * @property string $external_invoice_id
 * @property string|null $customer_id
 * @property string $external_customer_id
 * @property string $customer_name
 * @property string $customer_phone
 * @property string $customer_email
 * @property string $asset_name
 * @property string $asset_serial
 * @property string $external_asset_id
 * @property string $discount_type
 * @property string $discount_value
 * @property string $goods_total
 * @property string $paid_elsewhere
 * @property bool $completed_in_bikebook
 * @property string $billing_type
 * @property CarbonImmutable|null $due_at
 * @property string|null $notes
 * @property int $version
 * @property string $content_hash
 * @property int|null $hub_version
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $pulled_at
 * @property CarbonImmutable|null $locked_at
 * @property string|null $reported_status
 * @property string|null $reported_paid_total
 * @property string|null $reported_refunded_total
 * @property CarbonImmutable|null $reported_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, WorkshopOrderLine> $lines
 * @property-read WorkshopConnection|null $connection
 */
class WorkshopOrder extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'workshop_orders';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkshopOrderStatus::class,
            'discount_value' => MoneyCast::class,
            'goods_total' => MoneyCast::class,
            'paid_elsewhere' => MoneyCast::class,
            'completed_in_bikebook' => 'boolean',
            'due_at' => 'immutable_datetime',
            'version' => 'integer',
            'hub_version' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'pulled_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'reported_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<WorkshopOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkshopOrderLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<WorkshopConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(WorkshopConnection::class);
    }
}
