<?php

namespace App\Domain\MasterCatalogue\Models;

use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Support\PackSize;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One product of the platform-wide master catalogue (starter catalogue and barcode lookup). Not tenant data: no
 * company, owned by SSPOS staff on /admin/catalogue. Businesses copy rows into their own catalogue (AddFromCatalogue);
 * nothing here is ever sent to a till directly.
 *
 * @property string $id
 * @property string $barcode
 * @property string $name
 * @property string|null $brand
 * @property string|null $size_value
 * @property string|null $size_unit
 * @property int|null $pack_qty
 * @property string|null $department
 * @property string|null $category
 * @property string|null $vat_rate
 * @property string|null $rrp
 * @property string $age_rule
 * @property string|null $image_url
 * @property bool $in_starter_packs
 * @property MasterSource $source
 * @property string|null $source_ref
 * @property string|null $merged_into_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MasterProduct extends Model
{
    use HasUlids;

    /** Columns an admin edits and a CSV row may fill. */
    public const FIELDS = ['barcode', 'name', 'brand', 'size_value', 'size_unit', 'pack_qty', 'department', 'category', 'vat_rate', 'rrp', 'age_rule', 'image_url', 'in_starter_packs'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => MasterSource::class,
            'pack_qty' => 'integer',
            'in_starter_packs' => 'boolean',
            'size_value' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'rrp' => 'decimal:2',
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /**
     * Rows that are products in their own right (not merged into another).
     *
     * @param  Builder<MasterProduct>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('merged_into_id');
    }

    public function size(): PackSize
    {
        return new PackSize($this->size_value, $this->size_unit, $this->pack_qty);
    }

    public function isAgeRestricted(): bool
    {
        return $this->age_rule !== 'none';
    }
}
