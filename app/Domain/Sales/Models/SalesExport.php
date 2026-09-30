<?php

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sales CSV export built by a queued job (module 4.6): too many rows to stream while the user waits. Only the user
 * who asked may download it, for DAYS_KEPT days.
 *
 * @property string $id
 * @property string $company_id
 * @property int|null $user_id
 * @property ExportStatus $status
 * @property array<string, mixed> $filters
 * @property string|null $path
 * @property int $row_count
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class SalesExport extends Model
{
    use BelongsToCompany, HasUlids;

    /** Up to this many sales the CSV streams straight away; more is queued. */
    public const STREAM_ROWS = 5_000;

    public const DAYS_KEPT = 7;

    public const DISK = 'local';

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'finished_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    public function fileName(): string
    {
        return 'sales-'.($this->filters['from'] ?? 'from').'-to-'.($this->filters['to'] ?? 'to').'.csv';
    }

    public function isExpired(): bool
    {
        return $this->created_at !== null && $this->created_at->lt(now('UTC')->subDays(self::DAYS_KEPT));
    }

    /**
     * The user's latest exports for the sales screen.
     *
     * @return list<array{id: string, status: string, rows: int, from: string|null, to: string|null, createdAt: string|null, downloadable: bool}>
     */
    public static function recentFor(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return self::query()->where('user_id', $userId)->where('created_at', '>=', now('UTC')->subDays(self::DAYS_KEPT)->format('Y-m-d H:i:s'))
            ->latest('created_at')->latest('id')->limit(5)->get()
            ->map(fn (self $e) => [
                'id' => $e->id,
                'status' => $e->status->value,
                'rows' => $e->row_count,
                'from' => isset($e->filters['from']) ? (string) $e->filters['from'] : null,
                'to' => isset($e->filters['to']) ? (string) $e->filters['to'] : null,
                'createdAt' => $e->created_at?->toIso8601ZuluString(),
                'downloadable' => $e->status === ExportStatus::Ready && $e->path !== null,
            ])->values()->all();
    }
}
