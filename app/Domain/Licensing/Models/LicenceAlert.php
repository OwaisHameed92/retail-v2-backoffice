<?php

namespace App\Domain\Licensing\Models;

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin alert raised by the licence API (module 1.5). Repeats from the same PC count up one open row
 * (`count`, `last_seen_at`) until staff resolve it. `details` never holds a key or a full device id.
 * Tenant-owned: admin and API code read it with `LicenceAlert::withoutCompanyScope()`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $licence_id
 * @property LicenceAlertType $type
 * @property string $fingerprint
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property int $count
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Licence|null $licence
 */
class LicenceAlert extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'licence_id',
        'type',
        'fingerprint',
        'details',
        'first_seen_at',
        'last_seen_at',
        'count',
    ];

    /** @var list<string> */
    protected $hidden = ['fingerprint'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LicenceAlertType::class,
            'details' => 'array',
            'count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Licence, $this>
     */
    public function licence(): BelongsTo
    {
        return $this->belongsTo(Licence::class)->withoutGlobalScopes();
    }

    /**
     * @param  Builder<LicenceAlert>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('resolved_at'));
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
