<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;

/**
 * An added till's setup fee invoice is paid (P11): its tills are no longer held, and they unlock by the same rule as
 * the first setup fee. Runs inside SettleInvoice (the company's billing lock held). Idempotent (`licences_renewed_at`).
 *
 * - Setup-only plan: nothing here; ApplySetupFeeTerms (run right after the payment, and by billing:run) gives the
 *   till the full licence like every other till.
 * - Recurring plan: a till on its trial that ends before the business's other tills are paid to gets its trial moved
 *   to that date (as bridgeTrial does for the first setup fee), so it trades now; the next period invoice renews it
 *   with the others. Never shortens a date.
 */
class UnlockAddedTills
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return list<Licence> always empty: no paid period is renewed here
     */
    public function handle(Invoice $invoice, CarbonImmutable $now): array
    {
        if ($invoice->licences_renewed_at !== null) {
            return [];
        }

        $invoice->licences_renewed_at = $now;
        $invoice->save();

        $company = $invoice->company;

        if ($company === null || ApplySetupFeeTerms::planType($company) === PlanBillingType::SetupOnly) {
            return [];
        }

        $mine = $invoice->lines()->whereNotNull('licence_id')->pluck('licence_id')->map(fn ($id) => (string) $id)->all();
        $exclude = [...$mine, ...SetupFeeTills::heldLicenceIds($company->id)];
        $paidUntil = RenewCompanyLicences::renewable($company)->whereNotIn('id', $exclude)->whereNotNull('expires_at')->max('expires_at');
        $until = is_string($paidUntil) && $paidUntil !== '' ? CarbonImmutable::parse($paidUntil, 'UTC') : null;

        if ($until === null || $until->lessThanOrEqualTo($now)) {
            return [];
        }

        foreach (RenewCompanyLicences::renewable($company)->whereIn('id', $mine)->get() as $licence) {
            /** @var Licence $licence */
            if ($licence->isRevoked() || $licence->expires_at !== null || $licence->activated_at === null
                || ($licence->trial_ends_at !== null && $licence->trial_ends_at->greaterThanOrEqualTo($until))) {
                continue;
            }

            $locked = LicenceGuard::lock($licence);
            $before = ['trial_ends_at' => $locked->trial_ends_at?->toIso8601String(), 'status' => $locked->status->value];
            $locked->trial_ends_at = $until;
            $locked->status = LicenceTerms::storedStatusAfterDateChange($locked, $now);
            $locked->save();

            $this->audit->handle('licence.trial_extended', $locked, $before, ['trial_ends_at' => $until->toIso8601String(), 'status' => $locked->status->value], [
                'reason' => 'Setup fee for the added till paid ('.$invoice->number.')',
            ], companyId: $company->id);
        }

        return [];
    }
}
