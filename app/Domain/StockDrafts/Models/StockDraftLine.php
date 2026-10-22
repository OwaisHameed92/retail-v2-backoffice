<?php

namespace App\Domain\StockDrafts\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a stock draft: a counted product (stock take), a delivered quantity on an order line (goods receipt)
 * or a returned quantity (purchase return). `expected_quantity` / `variance` hold the till's outcome of a stock
 * take (and, on a delivery, what the portal saw outstanding).
 *
 * @property string $id
 * @property string $company_id
 * @property string $stock_draft_id
 * @property int $position
 * @property string $product_id
 * @property string $product_name
 * @property string|null $purchase_order_line_id
 * @property string $quantity
 * @property string|null $expected_quantity
 * @property string|null $variance
 * @property string|null $unit_cost
 * @property string|null $vat_rate_id
 * @property string|null $vat_percentage
 * @property int|null $case_qty
 * @property string|null $reason
 * @property string|null $note
 * @property int|null $hub_version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class StockDraftLine extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<StockDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(StockDraft::class, 'stock_draft_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'case_qty' => 'integer',
            'hub_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
