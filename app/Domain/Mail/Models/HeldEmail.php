<?php

namespace App\Domain\Mail\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An email not sent because its category is off (P11), waiting for an admin to send or discard it. The message is
 * kept encrypted with the app key (it can hold licence keys or a password link, as the queue payload does) and is
 * cleared once it is sent or discarded. Not tenant-scoped on purpose (admin screens), like EmailLog.
 *
 * @property string $id
 * @property string|null $email_log_id
 * @property string|null $company_id
 * @property EmailCategory $category
 * @property string $template
 * @property string $to
 * @property string|null $subject
 * @property string|null $payload
 * @property string $status held, sent or discarded
 * @property string|null $actioned_by_admin_id
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $company
 * @property-read EmailLog|null $emailLog
 * @property-read Admin|null $actionedBy
 */
class HeldEmail extends Model
{
    use HasUlids;

    public const HELD = 'held';

    public const SENT = 'sent';

    public const DISCARDED = 'discarded';

    protected $table = 'held_emails';

    /** @var list<string> */
    protected $fillable = ['email_log_id', 'company_id', 'category', 'template', 'to', 'subject', 'payload', 'status'];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => EmailCategory::class,
            'payload' => 'encrypted',
            'actioned_at' => 'datetime',
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
     * @return BelongsTo<EmailLog, $this>
     */
    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function actionedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actioned_by_admin_id');
    }

    /**
     * @param  Builder<HeldEmail>  $query
     */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', self::HELD);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::HELD;
    }

    /** The message as it was held, or null when it is gone (sent, discarded) or cannot be read. */
    public function mailable(): ?BrandedMailable
    {
        if ($this->payload === null) {
            return null;
        }

        try {
            $mailable = unserialize($this->payload);
        } catch (\Throwable) {
            return null;
        }

        return $mailable instanceof BrandedMailable ? $mailable : null;
    }
}
