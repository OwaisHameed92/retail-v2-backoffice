<?php

namespace App\Domain\Billing\GoCardless\Models;

use App\Domain\Billing\Casts\CalendarDateCast;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One GoCardless payment and the invoice of ours it collects. Created when we create the payment (setup fee) or
 * when GoCardless tells us about it (subscription); its status follows GoCardless. `payment_id` is our Payment,
 * recorded once the money is confirmed. Tenant-owned; admin code reads it with `withoutCompanyScope()`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $gc_payment_id
 * @property string|null $gc_subscription_id
 * @property string|null $gc_mandate_id
 * @property PaymentKind $kind
 * @property string|null $invoice_id
 * @property string|null $payment_id
 * @property string $amount
 * @property CarbonImmutable|null $charge_date
 * @property PaymentStatus $status
 * @property string|null $description
 * @property int|null $instalment
 * @property int|null $instalments
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $failure_notified_at
 * @property CarbonImmutable|null $reminder_sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Invoice|null $invoice
 * @property-read Payment|null $payment
 */
class GoCardlessPayment extends Model
{
    use BelongsToCompany, HasPortalUlid;

    protected $table = 'gocardless_payments';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'status' => PaymentStatus::class,
            'amount' => MoneyCast::class,
            'charge_date' => CalendarDateCast::class,
            'instalment' => 'integer',
            'instalments' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'failure_notified_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withoutGlobalScope(CompanyScope::class);
    }
}
