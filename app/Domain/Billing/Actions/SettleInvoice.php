<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
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
 * An invoice has nothing left to pay: mark it paid and renew exactly the licences on its lines (a branch line:
 * that branch's live tills) to the end of the period (23:59:59 London on the last day) through RenewLicence, then email the owners "licences renewed" with
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
        private readonly UnlockAddedTills $unlockAddedTills,
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

        // P11: an added till's setup fee renews nothing itself; it lets go of the tills it held (UnlockAddedTills).
        $renewed = $invoice->kind === InvoiceKind::TillSetupFee
            ? $this->unlockAddedTills->handle($invoice, $now)
            : $this->renewLicences($invoice, $now);

        $company = $invoice->company;
        // A paid setup fee (the upfront payment, module 1.13) does not end the trial; a paid period does.
        if ($company !== null && $company->status === CompanyStatus::Trial && ! $invoice->kind->isSetupFee()) {
            try {
                $this->activateCompany->handle($company);
            } catch (ValidationException) {
                // Status changed in between; nothing to do.
            }
        }

        return $renewed;
    }

    /**
     * The licences an invoice pays for: each till line's licence, and every live till of each branch line
     * (per-branch pricing, module 1.13) as it is now. Keyed by licence id; null = the licence is gone.
     *
     * @return array<string, Licence|null>
     */
    private function lineLicences(Invoice $invoice): array
    {
        $licences = [];
        $company = $invoice->company;

        foreach ($invoice->lines()->where(fn ($q) => $q->whereNotNull('licence_id')->orWhereNotNull('branch_id'))->get() as $line) {
            /** @var InvoiceLine $line */
            if ($line->licence_id !== null) {
                $licences[$line->licence_id] = Licence::withoutCompanyScope()->find($line->licence_id);

                continue;
            }

            if ($company !== null) {
                foreach (RenewCompanyLicences::renewable($company)->where('branch_id', $line->branch_id)->get() as $licence) {
                    $licences[$licence->id] = $licence;
                }
            }
        }

        return $licences;
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
            // P11: tills waiting for their added-till setup fee stay on their trial until it is paid.
            $held = SetupFeeTills::heldLicenceIds($invoice->company_id);

            foreach ($this->lineLicences($invoice) as $id => $licence) {
                if ($licence === null || $licence->isRevoked() || $licence->company_id !== $invoice->company_id || in_array($licence->id, $held, true)) {
                    $skipped[] = $id;

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
