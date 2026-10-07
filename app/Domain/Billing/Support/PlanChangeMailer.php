<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Data\PlanChangePlan;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Mail\Data\PlanChangedData;
use App\Domain\Mail\Mailables\PlanChangedMail;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Plans\Enums\Feature;
use Illuminate\Support\Facades\Mail;

/**
 * "Your plan has changed" to the business's active owners, in the customer's words (category Reminders and notices:
 * held while that category is not sent automatically, P11). Returns how many were queued.
 */
final class PlanChangeMailer
{
    public function __construct(private readonly LicenceMailer $licenceMailer) {}

    public function send(PlanChangePlan $plan, ?Invoice $setupInvoice, ?Invoice $firstInvoice): int
    {
        $company = $plan->company;
        $owners = $this->licenceMailer->owners($company);
        $needsMandate = $plan->startsMandateSetup && $plan->mandateDeadlineAt !== null;

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new PlanChangedMail(new PlanChangedData(
                businessName: $company->name,
                ownerName: $owner->name,
                fromPlan: $plan->from?->name,
                toPlan: $plan->to->name,
                changedOn: $plan->today,
                changes: self::changes($plan, $firstInvoice),
                setupFee: $setupInvoice?->total,
                setupInvoice: $setupInvoice?->number,
                directDebitUrl: $needsMandate ? config('sspos.portal_url').'/app/billing' : null,
                directDebitBy: $needsMandate ? $plan->mandateDeadlineAt : null,
                howToPay: $plan->manual && ($setupInvoice !== null || $plan->newRecurs)
                    ? 'Pay each invoice by '.ManualCollection::methodsText().', quoting the invoice number as the reference.'
                    : null,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    /**
     * @return list<string>
     */
    private static function changes(PlanChangePlan $plan, ?Invoice $firstInvoice): array
    {
        $names = fn (array $features) => implode(', ', array_map(fn (Feature $f) => $f->label(), $features));
        $per = $plan->cycle->value === 'yearly' ? 'a year' : 'a month';
        $lines = [];

        if ($plan->gained !== []) {
            $lines[] = 'New features: '.$names($plan->gained).'.';
        }

        if ($plan->lost !== []) {
            $lines[] = 'No longer included: '.$names($plan->lost).'.';
        }

        foreach ($plan->customShops as $shop) {
            $lines[] = "{$shop['name']} keeps the features set up for it.";
        }

        if ($plan->chargesSetupFee()) {
            $lines[] = 'Setup fee: we have emailed you the invoice, to pay by '.($plan->manual ? ManualCollection::methodsText() : 'cash, card or bank transfer').'.';
        }

        if ($plan->stopsRecurring()) {
            $lines[] = 'There is no monthly or yearly fee any more'.($plan->liveSubscription ? ': your Direct Debit is cancelled.' : '.');
        } elseif ($plan->newRecurs) {
            $amount = MailFormat::money($plan->newRecurring)." {$per}";
            $lines[] = match (true) {
                $plan->firstPeriodNow && $firstInvoice !== null => "Fee: {$amount}, starting today ({$firstInvoice->number}"
                    .($plan->manual ? ', emailed to you' : ', collected by Direct Debit').').',
                $plan->startsRecurring() => "Fee: {$amount}, starting once your setup fee is paid and any free trial has ended.",
                default => "Fee: {$amount} from your next period.",
            };
        }

        $lines[] = match (true) {
            $plan->firstPeriodNow && $plan->periodEnd !== null => 'Your tills stay valid until '.MailFormat::date($plan->periodEnd).' and renew as each period is paid.',
            $plan->fullTermNow && $plan->fullTermUntil !== null => 'Your tills are licensed until '.MailFormat::date($plan->fullTermUntil).'.',
            ! $plan->newRecurs => 'Your tills keep their current dates until the setup fee is paid, then get a long-term licence.',
            default => 'Your tills keep their current dates.',
        };

        return $lines;
    }
}
