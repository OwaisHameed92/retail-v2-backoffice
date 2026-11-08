<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Shared\Casts\QuantityCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Workshop\Enums\WorkshopLineType;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a BikeBook sales order (WORKSHOP-BIKEBOOK.md §3.6, §5.1). Our `id` is the line id BikeBook sends back
 * on `PUT`/`DELETE /api/SalesOrderLine`; BikeBook's own line id, when sent, is `external_line_id`. `unit_price` is inc
 * VAT; `line_total` / `vat_amount` as the till's VatCalculator works them out (the till recomputes).
 *
 * @property string $id
 * @property string $company_id
 * @property string $workshop_order_id
 * @property int $position
 * @property string $external_line_id
 * @property string|null $product_id
 * @property string $name
 * @property string $quantity
 * @property string $unit_price
 * @property string $vat_rate_id
 * @property string $vat_percentage
 * @property WorkshopLineType $line_type
 * @property string $external_line_type
 * @property string $line_total
 * @property string $vat_amount
 */
class WorkshopOrderLine extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'workshop_order_lines';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => QuantityCast::class,
            'unit_price' => MoneyCast::class,
            'line_type' => WorkshopLineType::class,
            'line_total' => MoneyCast::class,
            'vat_amount' => MoneyCast::class,
        ];
    }
}
