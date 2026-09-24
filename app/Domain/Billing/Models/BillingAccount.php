<?php

namespace App\Domain\Billing\Models;

use App\Domain\Billing\Enums\BillingCycle;
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Company|null $company
 */
class BillingAccount extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'cycle' => 'monthly',
        'payment_terms_days' => 7,
        'vat_applies' => true,
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
     * Where invoices go: the billing emails, or null to use the company's active owners.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values(array_filter(array_map('trim', $this->billing_emails ?? []), fn (string $email) => $email !== ''));
    }
}
