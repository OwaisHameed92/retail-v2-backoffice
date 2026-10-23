<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Shared\Casts\QuantityCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of an online order: the till product's id with its name and shelf price as they were when ordered.
 * A weighed line's quantity is in kg (0.25 kg steps) and its total is an estimate: the till weighs and charges it.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_order_id
 * @property int $position
 * @property string $product_id
 * @property string $name
 * @property string $unit_price
 * @property string $quantity
 * @property bool $is_weighed
 * @property string $line_total
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineOrderLine extends Model
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
            'position' => 'integer',
            'unit_price' => MoneyCast::class,
            'quantity' => QuantityCast::class,
            'is_weighed' => 'boolean',
            'line_total' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
