<?php

namespace App\Domain\MasterCatalogue\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin CSV load into the master catalogue (a licensed supplier file), applied in chunks by MasterImportJob.
 *
 * @property string $id
 * @property string|null $admin_id
 * @property string $file_name
 * @property string $path
 * @property string|null $source_ref
 * @property ImportStatus $status
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $failed_count
 * @property list<array{row: int, messages: list<string>}>|null $errors
 * @property int $cursor
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MasterCatalogueImport extends Model
{
    use HasUlids;

    public const MAX_ERRORS = 500;

    protected $table = 'master_product_imports';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'errors' => 'array',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'failed_count' => 'integer',
            'cursor' => 'integer',
            'started_at' => UtcDateTimeCast::class,
            'finished_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
