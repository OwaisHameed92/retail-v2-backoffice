<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A day a shop is closed for online orders, or has special hours (audit OSA-13, SaveStoreClosure): bank holidays,
 * Christmas, a staff shortage. Without hours: no collection or delivery slots that day. With hours ("10:00"–"14:00",
 * shop time): that day's collection and delivery slots run in those hours only. `date` is the shop's date.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_shop_id
 * @property string $date "Y-m-d" (kept as text, so it compares the same on SQLite and MySQL)
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineStoreClosure extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * Special hours, or null when closed all day.
     *
     * @return array{open: string, close: string}|null
     */
    public function hours(): ?array
    {
        return $this->opens_at !== null && $this->closes_at !== null ? ['open' => $this->opens_at, 'close' => $this->closes_at] : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
