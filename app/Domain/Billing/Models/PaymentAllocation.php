<?php

namespace App\Domain\Billing\Models;

use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a payment put towards one invoice. `released_at` is set when that invoice is voided: the amount goes
 * back to the payment's unallocated credit and the row stays for history.
 *
 * @property string $id
 * @property string $company_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property string $amount
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $created_at
 * @property-read Payment|null $payment
 * @property-read Invoice|null $invoice
 */
class PaymentAllocation extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope(CompanyScope::class);
    }
}
