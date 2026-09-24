<?php

namespace App\Domain\Ai\Models;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change proposed by an AI write tool, waiting for the proposer to confirm. Created only by ToolExecutor;
 * moved on only by ConfirmAiAction / CancelAiAction. Load through `ownedBy($context)`.
 *
 * @property string $id
 * @property string|null $company_id
 * @property int|null $user_id
 * @property string|null $admin_id
 * @property string|null $conversation_id
 * @property string $tool
 * @property array<string, mixed> $input
 * @property string $preview
 * @property PendingActionStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property Carbon $expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiPendingAction extends Model
{
    use HasPortalUlid, MassPrunable;

    protected $table = 'ai_pending_actions';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'admin_id',
        'conversation_id',
        'tool',
        'input',
        'preview',
        'status',
        'result',
        'error',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'status' => PendingActionStatus::class,
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @param  Builder<AiPendingAction>  $query
     */
    public function scopeOwnedBy(Builder $query, AiContext $context): void
    {
        $query->where('company_id', $context->companyId())
            ->where('user_id', $context->userId())
            ->where('admin_id', $context->adminId());
    }

    public function isOwnedBy(AiContext $context): bool
    {
        return $this->company_id === $context->companyId()
            && ($this->user_id === null ? null : (int) $this->user_id) === $context->userId()
            && $this->admin_id === $context->adminId();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('ai.retention_days', 90));

        return self::query()->where('created_at', '<', now()->subDays($days));
    }
}
