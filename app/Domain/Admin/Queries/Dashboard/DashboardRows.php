<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The rows the admin dashboard is worked out from, read once across every tenant (admin code: no company scope).
 * Five queries whatever the number of tenants; every figure is then computed in PHP from these rows.
 */
final class DashboardRows
{
    /** How far back series look: 24 weeks (12 shown + 12 to compare) or the start of last month. */
    public const HISTORY_WEEKS = 24;

    /**
     * @param  Collection<string, Company>  $companies  Every company, soft-deleted ones too, keyed by id.
     * @param  Collection<int, Licence>  $licences  Licences not revoked or deleted before the history window.
     * @param  Collection<string, Plan>  $plans  Keyed by id.
     * @param  Collection<int, Invoice>  $paid  Paid invoices since the history window (paid_at, total).
     * @param  Collection<int, Invoice>  $overdue  Invoices that went overdue and were still open during the window.
     */
    public function __construct(
        public readonly CarbonImmutable $now,
        public readonly CarbonImmutable $since,
        public readonly Collection $companies,
        public readonly Collection $licences,
        public readonly Collection $plans,
        public readonly Collection $paid,
        public readonly Collection $overdue,
    ) {}

    public static function load(CarbonImmutable $now): self
    {
        $weeks = Buckets::weeks($now, self::HISTORY_WEEKS);
        $since = $weeks[0]['start']->min(Buckets::monthStart($now)->setTimezone(Country::zone())->subMonthNoOverflow()->utc());
        $notGoneBefore = fn (string $column) => fn (Builder $q) => $q->whereNull($column)->orWhere($column, '>=', $since);

        return new self(
            now: $now,
            since: $since,
            companies: Company::withTrashed()
                ->get(['id', 'name', 'status', 'plan_id', 'created_at', 'cancelled_at', 'suspended_at', 'deleted_at'])
                ->keyBy('id'),
            licences: Licence::withoutCompanyScope()->withTrashed()
                ->where($notGoneBefore('deleted_at'))
                ->where($notGoneBefore('revoked_at'))
                ->get([
                    'id', 'company_id', 'plan_id', 'status', 'live_register_id', 'device_id', 'bound_at', 'activated_at',
                    'trial_ends_at', 'expires_at', 'grace_ends_at', 'suspended_at', 'revoked_at', 'deleted_at', 'last_validated_at',
                ]),
            plans: Plan::withTrashed()->get(['id', 'name', 'price_monthly'])->keyBy('id'),
            paid: self::paidSince($since),
            overdue: Invoice::withoutCompanyScope()
                ->whereNotNull('overdue_at')
                ->where(fn (Builder $q) => $q->whereIn('status', InvoiceStatus::openValues())
                    ->orWhere('paid_at', '>=', $since)
                    ->orWhere('voided_at', '>=', $since))
                ->get(['id', 'company_id', 'number', 'status', 'overdue_at', 'paid_at', 'voided_at', 'total', 'balance']),
        );
    }

    /**
     * @return Collection<int, Invoice>
     */
    public static function paidSince(CarbonImmutable $since): Collection
    {
        return Invoice::withoutCompanyScope()
            ->where('status', InvoiceStatus::Paid->value)
            ->where('paid_at', '>=', $since)
            ->get(['id', 'paid_at', 'total']);
    }

    /**
     * Paid invoice totals with paid_at in `[from, to)`.
     *
     * @param  Collection<int, Invoice>  $paid
     */
    public static function paidBetween(Collection $paid, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return Money::sum($paid
            ->filter(fn (Invoice $invoice) => $invoice->paid_at !== null && $invoice->paid_at->greaterThanOrEqualTo($from) && $invoice->paid_at->lessThan($to))
            ->pluck('total'));
    }

    public function company(string $id): ?Company
    {
        return $this->companies->get($id);
    }
}
