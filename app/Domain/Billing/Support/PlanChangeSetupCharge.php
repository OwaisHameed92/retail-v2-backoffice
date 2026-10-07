<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Data\PlanChangePlan;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The setup fee of a plan change (PlanChangeSetupFee worked it out), inside ChangeBusinessPlan's transaction with the
 * billing account locked; the caller saves the account and issues the draft.
 *
 * - The business's own setup fee (nothing paid before): a "Setup fee" draft for the amount; the amount becomes the
 *   business's setup fee, which counts as invoiced. 0: recorded as nothing to pay (no invoice).
 * - Tills not covered yet (per-till plan): a "Setup fee (added tills)" draft, a line per till (held until it is paid).
 *   0: waived.
 *
 * Either way every till the business has is then covered, so it is never charged again; a plan with a setup fee and
 * nothing to charge marks them covered too.
 */
final class PlanChangeSetupCharge
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(PlanChangePlan $plan, BillingAccount $account): ?Invoice
    {
        $company = $plan->company;
        $hasFee = $plan->to->billingType()->hasSetupFee() && ! Money::isZero($plan->to->setup_fee);

        if ($plan->setupKind === null || $plan->setupFee === null) {
            if ($hasFee) {
                SetupFeeTills::coverAll($company, $account);
            }

            return null;
        }

        $net = Money::normalise($plan->setupFee);
        SetupFeeTills::coverAll($company, $account);
        $meta = ['reason' => 'Plan changed to '.$plan->to->name, 'amount_label' => BillingFormat::money($net), 'suggested' => $plan->suggestedSetupFee];

        if ($plan->setupKind === InvoiceKind::SetupFee) {
            $account->setup_fee_override = $net;
            $account->setup_fee_invoiced_at ??= CarbonImmutable::now();

            if (Money::isZero($net)) {
                $this->audit->handle('billing.setup_fee_waived', $account, null, ['net' => $net, 'tills' => $plan->tills], $meta, companyId: $company->id);

                return null;
            }

            $this->audit->handle('billing.setup_fee_invoiced', $account, null, ['net' => $net, 'instalments' => 1], $meta + ['method' => 'manual'], companyId: $company->id);

            return SetupFeeInvoices::businessDraft($company, $account, $net, 'Setup fee · '.$plan->to->name.' plan · '.$company->name);
        }

        $tills = count($plan->setupLicences);

        if (Money::isZero($net)) {
            $this->audit->handle('billing.till_setup_fee_waived', $account, null, ['tills' => $tills], $meta, companyId: $company->id);

            return null;
        }

        $this->audit->handle('billing.till_setup_fee_invoiced', $account, null, ['tills' => $tills, 'net' => $net], $meta + ['covered_tills' => $account->setup_fee_covered_tills], companyId: $company->id);

        // Read again: the licences are on the new plan now.
        $licences = Licence::withoutCompanyScope()->whereKey(array_map(fn (Licence $licence) => $licence->id, $plan->setupLicences))
            ->with(['branch', 'register'])->orderBy('created_at')->get()->all();

        return SetupFeeInvoices::tillsDraft($company, $account, $licences, $net);
    }
}
