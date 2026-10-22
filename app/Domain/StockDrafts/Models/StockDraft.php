<?php

namespace App\Domain\StockDrafts\Models;

use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock document drafted on the portal for one shop (contract proposal pda-stock-drafts): portal-only, sent down
 * once in that shop's pull (StockDraftFeed) and owned by the till after that. Written by SaveStockDraft /
 * CancelStockDraft; the till's outcome is read back by ReconcileStockDrafts.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property StockDraftKind $kind
 * @property StockDraftState $state
 * @property string|null $handheld_user_id
 * @property string $entered_by
 * @property string|null $client_reference
 * @property int|null $number
 * @property string|null $reference
 * @property string|null $product_id
 * @property string|null $adjustment_type
 * @property string|null $quantity
 * @property string|null $unit_cost
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $product_unit_id
 * @property string|null $entered_quantity
 * @property string|null $conversion_to_base
 * @property string|null $stock_on_hand_before
 * @property string|null $name
 * @property string|null $supplier_id
 * @property string|null $supplier_name
 * @property string|null $purchase_order_id
 * @property string|null $reference_no
 * @property CarbonImmutable $happened_at
 * @property int|null $hub_version
 * @property CarbonImmutable|null $pulled_at
 * @property CarbonImmutable|null $outcome_at
 * @property string|null $reject_reason
 * @property string|null $qty_before
 * @property string|null $qty_delta
 * @property string|null $qty_after
 * @property string|null $stock_movement_id
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, StockDraftLine> $lines
 */
class StockDraft extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return HasMany<StockDraftLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockDraftLine::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StockDraftKind::class,
            'state' => StockDraftState::class,
            'number' => 'integer',
            'hub_version' => 'integer',
            'happened_at' => 'immutable_datetime',
            'pulled_at' => 'immutable_datetime',
            'outcome_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
