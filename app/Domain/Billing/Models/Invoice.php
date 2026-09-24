<?php

namespace App\Domain\Billing\Models;

use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\Casts\CalendarDateCast;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Shared\Casts\MoneyCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice to a tenant for one billing period: one line per live licence (till) at its plan price.
 * Tenant-owned (BelongsToCompany): admin code reads it with `Invoice::withoutCompanyScope()`.
 *
 * Change it only through the actions in App\Domain\Billing\Actions. A draft has no number and may be edited
 * or deleted; once issued only the status, payments, credits and stamps change (corrections: void and
 * re-issue, or a credit note). Money is MoneyCast strings in pounds, never floats.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $number
 * @property int|null $sequence
 * @property InvoiceStatus $status
 * @property BillingCycle $cycle
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property string $currency
 * @property string $vat_rate
 * @property string|null $seller_vat_number
 * @property string $subtotal
 * @property string $vat_total
 * @property string $total
 * @property string $amount_paid
 * @property string $amount_credited
 * @property string $balance
 * @property string|null $bill_to_name
 * @property string|null $bill_to_address
 * @property list<string>|null $bill_to_emails
 * @property string|null $notes
 * @property bool $prorated
 * @property bool $auto_generated
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $overdue_at
 * @property CarbonImmutable|null $suspension_triggered_at
 * @property CarbonImmutable|null $licences_renewed_at
 * @property CarbonImmutable|null $last_sent_at
 * @property int $sent_count
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property string|null $created_by_admin_id
 * @property string|null $issued_by_admin_id
 * @property string|null $voided_by_admin_id
 * @property string|null $replaces_invoice_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Company|null $company
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, PaymentAllocation> $allocations
 * @property-read Collection<int, CreditNote> $creditNotes
 */
class Invoice extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'GBP',
        'vat_rate' => '0.00',
        'subtotal' => '0.00',
        'vat_total' => '0.00',
        'total' => '0.00',
        'amount_paid' => '0.00',
        'amount_credited' => '0.00',
        'balance' => '0.00',
        'sent_count' => 0,
        'prorated' => false,
        'auto_generated' => false,
    ];

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'cycle' => BillingCycle::class,
            'sequence' => 'integer',
            'period_start' => CalendarDateCast::class,
            'period_end' => CalendarDateCast::class,
            'issue_date' => CalendarDateCast::class,
            'due_date' => CalendarDateCast::class,
            'vat_rate' => MoneyCast::class,
            'subtotal' => MoneyCast::class,
            'vat_total' => MoneyCast::class,
            'total' => MoneyCast::class,
            'amount_paid' => MoneyCast::class,
            'amount_credited' => MoneyCast::class,
            'balance' => MoneyCast::class,
            'bill_to_emails' => 'array',
            'prorated' => 'boolean',
            'auto_generated' => 'boolean',
            'sent_count' => 'integer',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'overdue_at' => 'immutable_datetime',
            'suspension_triggered_at' => 'immutable_datetime',
            'licences_renewed_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The company, even when soft deleted.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * Lines in order. Read through the (already company-scoped) invoice, so the child scope is not applied again.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->withoutGlobalScope(CompanyScope::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Payments allocated to this invoice that still count (not released by a void).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->withoutGlobalScope(CompanyScope::class)->whereNull('released_at');
    }

    /**
     * Every allocation, released ones included (history).
     *
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->withoutGlobalScope(CompanyScope::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id')->withoutGlobalScope(CompanyScope::class);
    }

    /**
     * Issued and still owed (issued, partly paid, overdue).
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), InvoiceStatus::openValues());
    }

    /**
     * Not void (drafts included): counts when checking whether a period is already invoiced.
     *
     * @param  Builder<Invoice>  $query
     */
    public function scopeNotVoid(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), '!=', InvoiceStatus::Void->value);
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** "INV-000123", or "Draft" before it is issued. */
    public function displayNumber(): string
    {
        return $this->number ?? 'Draft';
    }
}
