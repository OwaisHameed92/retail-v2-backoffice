<?php

namespace App\Domain\Leads\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Support\PhoneDigits;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonInterface;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A trial request (module 1.6). Not tenant data: it belongs to Switch & Save until ApproveTrial turns it into a
 * company (`company_id`). Status changes go through the lead actions only, so every change gets a timeline note
 * and an audit entry.
 *
 * @property string $id
 * @property string $business_name
 * @property string $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $phone_digits
 * @property string|null $town
 * @property string|null $postcode
 * @property int $shops_count
 * @property int $tills_count
 * @property BusinessType $business_type
 * @property string|null $current_system
 * @property string|null $message
 * @property LeadSource $source
 * @property LeadStatus $status
 * @property string|null $assigned_admin_id
 * @property CarbonInterface|null $follow_up_at
 * @property CarbonInterface|null $contacted_at
 * @property CarbonInterface|null $last_contacted_at
 * @property string|null $rejection_reason
 * @property CarbonInterface|null $rejected_at
 * @property string|null $company_id
 * @property CarbonInterface|null $converted_at
 * @property string|null $converted_by_admin_id
 * @property bool $consent_marketing
 * @property array<string, string>|null $utm
 * @property string|null $ip
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Admin|null $assignedAdmin
 * @property-read Admin|null $convertedBy
 * @property-read Company|null $company
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** Most shops and tills a lead may ask for (the approval dialog allows the same). */
    public const MAX_SHOPS = 20;

    public const MAX_TILLS = 100;

    /**
     * Details only. Status, assignment, follow-up and conversion fields are set by the actions.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_name',
        'contact_name',
        'email',
        'phone',
        'town',
        'postcode',
        'shops_count',
        'tills_count',
        'business_type',
        'current_system',
        'message',
        'source',
        'consent_marketing',
        'utm',
        'ip',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'source' => 'website',
        'business_type' => 'convenience',
        'shops_count' => 1,
        'tills_count' => 1,
        'consent_marketing' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_type' => BusinessType::class,
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'shops_count' => 'integer',
            'tills_count' => 'integer',
            'consent_marketing' => 'boolean',
            'utm' => 'array',
            'follow_up_at' => 'datetime',
            'contacted_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $lead): void {
            $lead->phone_digits = PhoneDigits::from($lead->phone);
        });
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'converted_by_admin_id');
    }

    /**
     * The tenant made from this lead. Company is not tenant-scoped, so this works from admin code.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Timeline, newest first.
     *
     * @return HasMany<LeadNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(LeadNote::class)->latest('created_at')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen() && ! $this->trashed();
    }

    public function isConverted(): bool
    {
        return $this->status === LeadStatus::Converted;
    }

    public function isFollowUpOverdue(?CarbonInterface $now = null): bool
    {
        return $this->follow_up_at !== null && $this->follow_up_at->lt($now ?? now());
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn (LeadStatus $s) => $s->value, LeadStatus::open()));
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }
}
