<?php

namespace App\Domain\OnlineStore\Models;

use App\Domain\OnlineStore\Enums\BannerLink;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One home page banner of a store (OS-8): a photo, a headline, a short text and a button that goes to a department,
 * a product, a search or an https address. Up to {@see self::MAX} per store, shown in `position` order when `is_on`.
 * Written by SaveStoreBanner, DeleteStoreBanner and OrderStoreBanners.
 *
 * @property string $id
 * @property string $company_id
 * @property string $online_store_id
 * @property int $position
 * @property string|null $photo_path on the public disk (StoreImages::BANNER); null = the template's photo
 * @property string|null $headline
 * @property string|null $text
 * @property string|null $button_label
 * @property BannerLink $link_type
 * @property string|null $link_target department id, product id, search words or https address
 * @property bool $is_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class OnlineStoreBanner extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const MAX = 6;

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'link_type' => BannerLink::class,
            'is_on' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
