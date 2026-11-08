<?php

namespace App\Domain\Pharmacy\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One NHSBSA weekly dm+d zip the portal holds and serves to pharmacy tills (W4, PORTAL-CHANGES-pharmacy.md item 12).
 * Not tenant data. Written in one insert only after the zip is fully stored (FetchDmdRelease); the newest row is the
 * manifest of `GET /api/v1/pharmacy/dmd/latest`.
 *
 * @property string $id
 * @property string $release_id
 * @property string $name
 * @property CarbonImmutable $release_date
 * @property string $week
 * @property string $archive_file_name
 * @property string $path
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DmdRelease extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** The newest release (by NHSBSA's date, then by when we stored it), or null. */
    public static function newest(): ?self
    {
        return self::query()->orderByDesc('release_date')->orderByDesc('published_at')->first();
    }

    /** The folder of this release's files on the dm+d disk. */
    public function folder(): string
    {
        return dirname($this->path);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'release_date' => 'immutable_date',
            'size_bytes' => 'integer',
            'published_at' => UtcDateTimeCast::class,
        ];
    }
}
