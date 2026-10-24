<?php

namespace App\Domain\TillImages\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\TillImages\Enums\ProductImageSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The picture of one till product (IMG-1, contract proposal product-images): one row per product and source. Written
 * by StoreTillProductImage and DeleteTillProductImage only. Portal-only: never a till row.
 *
 * @property string $id
 * @property string $company_id
 * @property string $product_id the till product's id
 * @property ProductImageSource $source
 * @property string $path the web copy on the public disk (TillImageFiles)
 * @property string $hash sha256 hex of the bytes the till sent
 * @property int $bytes size of the web copy
 * @property int $width of the web copy
 * @property int $height of the web copy
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProductImage extends Model
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
            'source' => ProductImageSource::class,
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
