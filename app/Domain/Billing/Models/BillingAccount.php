<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Casts\CalendarDateCast;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Billing settings of one company (created on first use by BillingAccounts::for()). Its row is also the lock
 * every money action takes, so payments, issues and voids of one company run one at a time.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $billing_name
 * @property string|null $billing_address
 * @property list<string>|null $billing_emails
 * @property BillingCycle $cycle
 * @property int $payment_terms_days
 * @property bool $vat_applies
 * @property CarbonImmutable|null $billing_suspended_at
 * @property string|null $suspension_invoice_id
 * @property CarbonImmutable|null $trial_reminder_for
 * @property CarbonImmutable|null $trial_reminder_sent_at
 * @property CarbonImmutable|null $trial_ended_for
 * @property CarbonImmutable|null $trial_ended_sent_at
 * @property BillingMode $billing_mode
 * @property string|null $setup_fee_override
 * @property SetupFeeMethod $setup_fee_method
 * @property int $setup_fee_instalments
 * @property CarbonImmutable|null $setup_fee_invoiced_at
 * @property string|null $gc_customer_id
 * @property string|null $gc_billing_request_id
 * @property string|null $gc_setup_url
 * @property CarbonImmutable|null $gc_setup_url_expires_at
 * @property CarbonImmutable|null $gc_setup_sent_at
 * @property string|null $gc_mandate_id
 * @property MandateStatus|null $gc_mandate_status
 * @property CarbonImmutable|null $gc_mandate_active_at
 * @property CarbonImmutable|null $gc_mandate_lost_at
 * @property CarbonImmutable|null $mandate_overdue_at
 * @property CarbonImmutable|null $mandate_grace_suspended_for
 * @property string|null $gc_subscription_id
 * @property SubscriptionStatus|null $gc_subscription_status
 * @property string|null $gc_subscription_amount
 * @property BillingCycle|null $gc_subscription_cycle
 * @property CarbonImmutable|null $gc_next_charge_date
 * @property CarbonImmutable|null $gc_reconciled_at
 * @property PricingMode|null $pricing_mode_override Module 1.13; null = the plan's.
 * @property string|null $price_monthly_override Net per unit; null = the plan's.
 * @property string|null $price_yearly_override Net per unit; null = the plan's.
 * @property string|null $upfront_amount Gross paid upfront at onboarding ("0.00" = nothing to pay).
 * @property PaymentMethod|null $upfront_method
 * @property CarbonImmutable|null $upfront_recorded_at
 * @property CarbonImmutable|null $mandate_deadline_at Direct Debit must be set up by then (billing:run suspends).
 * @property CarbonImmutable|null $mandate_reminder_for The deadline the "set up your Direct Debit" reminder went for.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Company|null $company
 */
class BillingAccount extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * The GoCardless setup link works without signing in: never in toArray()/JSON.
     *
     * @var list<string>
     */
    protected $hidden = ['gc_setup_url'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cycle' => 'monthly',
        'payment_terms_days' => 7,
        'vat_applies' => true,
        'billing_mode' => 'upfrontCash',
        'setup_fee_method' => 'manual',
        'setup_fee_instalments' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_emails' => 'array',
            'cycle' => BillingCycle::class,
            'payment_terms_days' => 'integer',
            'vat_applies' => 'boolean',
            'billing_suspended_at' => 'immutable_datetime',
            'trial_reminder_for' => 'immutable_datetime',
            'trial_reminder_sent_at' => 'immutable_datetime',
            'trial_ended_for' => 'immutable_datetime',
            'trial_ended_sent_at' => 'immutable_datetime',
            'billing_mode' => BillingMode::class,
            'setup_fee_override' => MoneyCast::class,
            'setup_fee_method' => SetupFeeMethod::class,
            'setup_fee_instalments' => 'integer',
            'setup_fee_invoiced_at' => 'immutable_datetime',
            'gc_setup_url' => 'encrypted',
            'gc_setup_url_expires_at' => 'immutable_datetime',
            'gc_setup_sent_at' => 'immutable_datetime',
            'gc_mandate_status' => MandateStatus::class,
            'gc_mandate_active_at' => 'immutable_datetime',
            'gc_mandate_lost_at' => 'immutable_datetime',
            'mandate_overdue_at' => 'immutable_datetime',
            'mandate_grace_suspended_for' => 'immutable_datetime',
            'gc_subscription_status' => SubscriptionStatus::class,
            'gc_subscription_amount' => MoneyCast::class,
            'gc_subscription_cycle' => BillingCycle::class,
            'gc_next_charge_date' => CalendarDateCast::class,
            'gc_reconciled_at' => 'immutable_datetime',
            'pricing_mode_override' => PricingMode::class,
            'price_monthly_override' => MoneyCast::class,
            'price_yearly_override' => MoneyCast::class,
            'upfront_amount' => MoneyCast::class,
            'upfront_method' => PaymentMethod::class,
            'upfront_recorded_at' => 'immutable_datetime',
            'mandate_deadline_at' => 'immutable_datetime',
            'mandate_reminder_for' => 'immutable_datetime',
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

    public function isDirectDebit(): bool
    {
        return $this->billing_mode === BillingMode::DirectDebit;
    }

    /** A mandate GoCardless accepts payments on. */
    public function hasUsableMandate(): bool
    {
        return $this->gc_mandate_id !== null && ($this->gc_mandate_status?->isUsable() ?? false);
    }

    /** A GoCardless subscription that is still collecting (or paused). */
    public function hasLiveSubscription(): bool
    {
        return $this->gc_subscription_id !== null && ($this->gc_subscription_status?->isLive() ?? false);
    }

    /**
     * Where invoices go: the billing emails, or null to use the company's active owners.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values(array_filter(array_map('trim', $this->billing_emails ?? []), fn (string $email) => $email !== ''));
    }
}
