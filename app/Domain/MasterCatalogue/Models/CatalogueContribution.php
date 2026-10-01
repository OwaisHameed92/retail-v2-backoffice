<?php

namespace App\Domain\MasterCatalogue\Models;

use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Support\PackSize;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A barcode tills sold that the master catalogue did not know, waiting for an admin. Anonymous by design: the row
 * holds the barcode, the name and size the till used, and how many times it was seen. Never a price, a business, a
 * shop or a till (CollectUnknownBarcodes strips everything else before it is queued).
 *
 * @property string $id
 * @property string $barcode
 * @property string $name
 * @property string|null $size_value
 * @property string|null $size_unit
 * @property int|null $pack_qty
 * @property int $seen_count
 * @property ContributionStatus $status
 * @property string|null $master_product_id
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CatalogueContribution extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContributionStatus::class,
            'pack_qty' => 'integer',
            'size_value' => 'decimal:4',
            'seen_count' => 'integer',
            'reviewed_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    public function size(): PackSize
    {
        return new PackSize($this->size_value, $this->size_unit, $this->pack_qty);
    }
}
