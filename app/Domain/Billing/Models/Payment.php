<?php

namespace App\Domain\Billing\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received from a tenant (receipt number PAY-000001). Allocated to invoices, oldest first unless staff
 * choose; whatever is not allocated stays on the company as credit (`unallocated`). Recorded only through
 * RecordPayment, which the online gateway will call too (`gateway`, `gateway_reference`).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property int $sequence
 * @property PaymentMethod $method
 * @property string $amount
 * @property string $unallocated
 * @property CarbonImmutable $received_at
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $received_by_admin_id
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Company|null $company
 * @property-read Admin|null $receivedBy
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
class Payment extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'unallocated' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'method' => PaymentMethod::class,
            'amount' => MoneyCast::class,
            'unallocated' => MoneyCast::class,
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
     * @return BelongsTo<Admin, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by_admin_id');
    }

    /**
     * Allocations that still count (a voided invoice releases its allocations back to credit).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->withoutGlobalScope(CompanyScope::class)->whereNull('released_at');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->withoutGlobalScope(CompanyScope::class);
    }
}
