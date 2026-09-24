<?php

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiUsageStatus;
use App\Domain\Ai\Support\CostCast;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One model call: tokens, cost in pounds (6 dp string) and latency. Written only by RecordAiUsage.
 * Not tenant-scoped (admin reports read across companies); tenant screens must filter by company_id.
 *
 * @property string $id
 * @property string|null $company_id
 * @property int|null $user_id
 * @property string|null $admin_id
 * @property string|null $conversation_id
 * @property AiFeature $feature
 * @property string $model
 * @property AiUsageStatus $status
 * @property string|null $stop_reason
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $total_tokens
 * @property string $cost_gbp
 * @property int $latency_ms
 * @property Carbon|null $created_at
 */
class AiUsage extends Model
{
    use HasPortalUlid, MassPrunable;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'admin_id',
        'conversation_id',
        'feature',
        'model',
        'status',
        'stop_reason',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'total_tokens',
        'cost_gbp',
        'latency_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'status' => AiUsageStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_gbp' => CostCast::class,
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $months = max(1, (int) config('ai.usage_retention_months', 24));

        return self::query()->where('created_at', '<', now()->subMonths($months));
    }
}
