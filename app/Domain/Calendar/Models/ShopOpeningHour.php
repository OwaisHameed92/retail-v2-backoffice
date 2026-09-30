<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekday of a shop's opening hours (module 5.9). Portal-only: the contract has no weekly-hours entity, so the
 * tills get the week as the `shop.trading_hours` setting text (SaveOpeningHours). `weekday` is ISO (1 = Monday).
 * `opens_at` / `closes_at` are local "HH:MM"; a closing time at or before the opening time runs past midnight.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property int $weekday
 * @property bool $is_closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 */
class ShopOpeningHour extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'shop_opening_hours';

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return Ulid::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'is_closed' => 'boolean'];
    }
}
