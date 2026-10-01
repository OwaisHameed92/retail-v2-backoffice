<?php

namespace App\Domain\Labels\Models;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product waiting for (or last given) a shelf-edge label in one shop (gap #6). Portal-only, tenant-owned. One row
 * per shop and product: queuing it again while pending updates the reason (dedupe); after printing it is re-queued
 * in place. `due_at` = the change starts later (a scheduled shop price): the label shows the price from then.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $product_id
 * @property bool $pending
 * @property LabelReason $reason
 * @property string|null $detail
 * @property int $copies
 * @property int $times_queued
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $due_at
 * @property int|null $queued_by_user_id
 * @property CarbonImmutable|null $printed_at
 * @property int|null $printed_by_user_id
 * @property-read Product|null $product
 * @property-read Branch|null $branch
 */
class LabelQueueItem extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pending' => 'boolean',
            'reason' => LabelReason::class,
            'copies' => 'integer',
            'times_queued' => 'integer',
            'queued_at' => UtcDateTimeCast::class,
            'due_at' => UtcDateTimeCast::class,
            'printed_at' => UtcDateTimeCast::class,
            'queued_by_user_id' => 'integer',
            'printed_by_user_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
