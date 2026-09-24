<?php

namespace App\Domain\Ai\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation with the AI. Owned by one tenant user in one company, or by one admin.
 *
 * Not tenant-scoped with BelongsToCompany on purpose: admin conversations have no company. Always load through
 * `ownedBy($context)` (company AND user/admin), never by id alone. Pruned after `ai.retention_days` idle.
 *
 * @property string $id
 * @property string|null $company_id
 * @property int|null $user_id
 * @property string|null $admin_id
 * @property AiFeature $feature
 * @property string|null $title
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiConversation extends Model
{
    use HasPortalUlid, MassPrunable;

    protected $table = 'ai_conversations';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'admin_id', 'feature', 'title', 'last_message_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Conversations of exactly this actor (and company).
     *
     * @param  Builder<AiConversation>  $query
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

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('ai.retention_days', 90));

        return self::query()->where('updated_at', '<', now()->subDays($days));
    }
}
