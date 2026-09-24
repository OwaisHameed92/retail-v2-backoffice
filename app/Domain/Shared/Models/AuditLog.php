<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One immutable audit entry. Create it through {@see RecordAudit}.
 *
 * Not tenant-scoped on purpose: admins read across companies. Tenant screens must filter by company_id.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'meta',
        'ip',
        'user_agent',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Audit log entries cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
