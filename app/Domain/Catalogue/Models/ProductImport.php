<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product CSV import (module 4.2): the uploaded file, the column mapping, the preview and the result.
 *
 * @property string $id
 * @property string $company_id
 * @property int|null $user_id
 * @property string $file_name
 * @property string $path
 * @property ImportStatus $status
 * @property list<string> $headers
 * @property array<string, int>|null $mapping
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $error_rows
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $unchanged_count
 * @property int $failed_count
 * @property list<array{row: int, messages: list<string>}>|null $errors
 * @property array<string, mixed>|null $preview
 * @property int $cursor
 * @property CarbonImmutable|null $previewed_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ProductImport extends Model
{
    use BelongsToCompany, HasUlids;

    /** Row errors kept for the screen; the counts are always complete. */
    public const MAX_ERRORS = 500;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'headers' => 'array',
            'mapping' => 'array',
            'errors' => 'array',
            'preview' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'unchanged_count' => 'integer',
            'failed_count' => 'integer',
            'cursor' => 'integer',
            'previewed_at' => UtcDateTimeCast::class,
            'started_at' => UtcDateTimeCast::class,
            'finished_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
