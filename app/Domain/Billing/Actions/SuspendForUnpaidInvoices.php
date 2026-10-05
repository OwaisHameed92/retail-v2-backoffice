<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * billing:run step: a company with an invoice still overdue more than `billing.suspend_after_days` (14) days
 * after its due date is suspended with the reason "Invoice INV-000123 unpaid" (SuspendCompany: the tills lock at
 * their next check-in) and the owners get AccountSuspendedMail with the amount overdue.
 *
 * Each invoice triggers this once: if staff lift the suspension by hand, the same invoice does not suspend the
 * company again (a later overdue invoice can, and so can the same invoice after a Direct Debit failure reopens
 * it). For a reopened invoice the grace counts from the reopening (`reopened_at`), so a late chargeback still gets
 * the full grace. A company already suspended or cancelled is left alone.
 */
class SuspendForUnpaidInvoices
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SuspendCompany $suspendCompany,
        private readonly BillingMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /** Returns how many companies were suspended. */
    public function handle(CarbonImmutable $now): int
    {
        $days = max(0, (int) config('billing.suspend_after_days', 14));
        $cutoff = BillingDates::today($now)->subDays($days)->format('Y-m-d');
        $reopenedBefore = $now->subDays($days);
        $suspended = 0;

        $companyIds = self::due(Invoice::withoutCompanyScope(), $cutoff, $reopenedBefore)
            ->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $company = Company::query()->find($companyId);

            if ($company === null || ! in_array($company->status, [CompanyStatus::Trial, CompanyStatus::Active, CompanyStatus::Overdue], true)) {
                continue;
            }

            $done = DB::transaction(function () use ($company, $cutoff, $reopenedBefore, $now) {
                $account = $this->accounts->lock($company);

                $invoices = self::due(Invoice::withoutCompanyScope()->where('company_id', $company->id), $cutoff, $reopenedBefore)
                    ->orderBy('due_date')->orderBy('sequence')->lockForUpdate()->get();

                if ($invoices->isEmpty()) {
                    return null;
                }

                /** @var Invoice $oldest */
                $oldest = $invoices->first();
                $reason = "Invoice {$oldest->number} unpaid";
                $company = $this->suspendCompany->handle($company, $reason);

                $account->billing_suspended_at = CarbonImmutable::instance($company->suspended_at ?? $now);
                $account->suspension_invoice_id = $oldest->id;
                $account->save();

                foreach ($invoices as $invoice) {
                    $invoice->suspension_triggered_at = $now;
                    $invoice->save();
                }

                $this->audit->handle('billing.company_suspended', $oldest, null, null, [
                    'number' => $oldest->number,
                    'reason' => $reason,
                    'days_overdue' => (int) $oldest->due_date?->diffInDays(BillingDates::today($now)),
                ]);

                $overdue = Money::sum(Invoice::withoutCompanyScope()->where('company_id', $company->id)
                    ->where('status', InvoiceStatus::Overdue->value)->pluck('balance'));

                return [$company, $reason, $overdue];
            });

            if ($done !== null) {
                [$company, $reason, $overdue] = $done;
                $this->mailer->suspended($company, $reason, $overdue, $now);
                $suspended++;
            }
        }

        return $suspended;
    }

    /**
     * Overdue invoices past the grace that have not suspended the company yet: due more than the grace ago, and
     * (for one a Direct Debit failure or chargeback reopened) reopened more than the grace ago.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public static function due(Builder $query, string $cutoff, CarbonImmutable $reopenedBefore): Builder
    {
        return $query->where('status', InvoiceStatus::Overdue->value)->whereNull('suspension_triggered_at')
            ->where('due_date', '<', $cutoff)
            ->where(fn (Builder $q) => $q->whereNull('reopened_at')->orWhere('reopened_at', '<', $reopenedBefore));
    }
}
