<?php

namespace App\Domain\Mail\Models;

use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Support\EmailLogRecorder;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One outgoing email. Written only by {@see EmailLogRecorder}.
 *
 * Never holds the body, licence keys, password links or tokens: `meta` is redacted before it is stored.
 * Not tenant-scoped on purpose (admins read across companies); tenant screens must filter by company_id.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $to
 * @property string $mailable
 * @property string $template
 * @property string|null $subject
 * @property EmailStatus $status
 * @property string|null $error
 * @property string|null $message_id
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $company
 */
class EmailLog extends Model
{
    use HasUlids, MassPrunable;

    protected $table = 'email_logs';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'to',
        'mailable',
        'template',
        'subject',
        'status',
        'error',
        'message_id',
        'sent_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
            'meta' => 'array',
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
     * Rows older than the retention period (12 months by default) are removed by `model:prune`.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $months = max(1, (int) config('sspos.email_log_retention_months', 12));

        return self::query()->where('created_at', '<', now()->subMonths($months));
    }
}
