<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * An invoice has nothing left to pay: mark it paid and renew exactly the licences on its lines to the end of the
 * period (23:59:59 London on the last day) through RenewLicence, then email the owners "licences renewed" with
 * the amount and invoice number. A trial company becomes active. Runs inside the caller's transaction, with the
 * company's billing lock held. Idempotent: an invoice renews its licences once.
 *
 * Skipped (and listed in the audit meta): licences revoked or deleted since, and licences already paid up to
 * the period end or later (a renewal never shortens a licence). Nothing is renewed when the period is over.
 */
class SettleInvoice
{
    public function __construct(
        private readonly RenewLicence $renewLicence,
        private readonly BillingMailer $mailer,
        private readonly ActivateCompany $activateCompany,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return list<Licence> the renewed licences
     */
    public function handle(Invoice $invoice, CarbonImmutable $now): array
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            $invoice->status = InvoiceStatus::Paid;
            $invoice->paid_at = $now;
            $invoice->save();

            $this->audit->handle('invoice.paid', $invoice, ['status' => 'open'], ['status' => InvoiceStatus::Paid->value], [
                'number' => $invoice->number,
                'total' => $invoice->total,
            ]);
        }

        $renewed = $this->renewLicences($invoice, $now);

        $company = $invoice->company;
        if ($company !== null && $company->status === CompanyStatus::Trial) {
            try {
                $this->activateCompany->handle($company);
            } catch (ValidationException) {
                // Status changed in between; nothing to do.
            }
        }

        return $renewed;
    }

    /**
     * @return list<Licence>
     */
    private function renewLicences(Invoice $invoice, CarbonImmutable $now): array
    {
        if ($invoice->licences_renewed_at !== null) {
            return [];
        }

        $expiresAt = BillingDates::endOfDay($invoice->period_end);
        $renewed = [];
        $skipped = [];

        if ($expiresAt->greaterThan($now)) {
            $term = RenewalTerm::until($expiresAt);

            foreach ($invoice->lines()->whereNotNull('licence_id')->get() as $line) {
                /** @var InvoiceLine $line */
                $licence = Licence::withoutCompanyScope()->find($line->licence_id);

                if ($licence === null || $licence->isRevoked() || $licence->company_id !== $invoice->company_id) {
                    $skipped[] = $line->licence_id;

                    continue;
                }

                $licence = LicenceGuard::lock($licence);

                if ($licence->expires_at !== null && $licence->expires_at->greaterThanOrEqualTo($expiresAt)) {
                    $skipped[] = $licence->id;

                    continue;
                }

                $renewed[] = $this->renewLicence->apply($licence, $term, $now, $expiresAt)->load(['branch', 'register']);
            }
        }

        $invoice->licences_renewed_at = $now;
        $invoice->save();

        $this->audit->handle('invoice.licences_renewed', $invoice, null, null, [
            'number' => $invoice->number,
            'renewed' => count($renewed),
            'skipped' => count($skipped),
            'until' => $invoice->period_end->format('Y-m-d'),
        ]);

        if ($renewed !== []) {
            $this->mailer->renewed($invoice, $renewed, $expiresAt);
        }

        return $renewed;
    }
}
