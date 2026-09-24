<?php

namespace App\Domain\Leads\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\LeadNoteKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on a lead's timeline: a note typed by staff, or a system note written by a lead action
 * (status change, assignment, follow-up…). Written through AddLeadNote / LeadTimeline only.
 *
 * @property string $id
 * @property string $lead_id
 * @property string|null $admin_id
 * @property LeadNoteKind $kind
 * @property string $body
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property-read Admin|null $admin
 * @property-read Lead $lead
 */
class LeadNote extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    public const MAX_LENGTH = 5000;

    /** @var list<string> */
    protected $fillable = ['lead_id', 'admin_id', 'kind', 'body', 'meta'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LeadNoteKind::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }
}
