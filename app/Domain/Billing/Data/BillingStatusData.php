<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The "Billing status" card (admin Billing tab and the portal, one shared component): the plan type, the setup fee
 * and how it was paid, the recurring fee and its Direct Debit, the state in a few words and "what happens next" with
 * the exact date, and the obvious next action. Plain sentences, built from BillingStatus.
 */
final class BillingStatusData
{
    private const TONES = [
        BillingStatus::PAID => 'success',
        BillingStatus::TRIAL => 'info',
        BillingStatus::WAITING_FOR_DD => 'warning',
        BillingStatus::INSTALMENT_DUE => 'info',
        BillingStatus::SETUP_FEE_DUE => 'danger',
        BillingStatus::PAYMENT_FAILED => 'danger',
        BillingStatus::OVERDUE => 'danger',
        BillingStatus::SUSPENDED => 'danger',
        BillingStatus::CANCELLED => 'neutral',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(BillingStatus $status, bool $portal = false): array
    {
        $type = ApplySetupFeeTerms::planType($status->company);
        [$action, $actionLabel] = self::action($status);

        return [
            'state' => $status->state,
            'group' => $status->group(),
            'tone' => self::TONES[$status->state],
            'headline' => self::headline($status),
            'daysLeft' => $status->daysLeft(),
            'locksOn' => $status->locksOn?->format('Y-m-d'),
            'planType' => $type === null ? null : ['value' => $type->value, 'label' => $type->label()],
            'setupFee' => self::setupFee($status),
            'recurring' => self::recurring($status),
            'next' => self::next($status, $portal),
            'action' => $action,
            'actionLabel' => $actionLabel,
            'failedPaymentId' => $status->failed?->id,
            'demo' => (bool) $status->company->is_demo,
        ];
    }

    private static function day(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->format('j M Y');
    }

    private static function headline(BillingStatus $status): string
    {
        $locks = self::day($status->locksOn);
        $days = $status->daysLeft();

        return match ($status->state) {
            BillingStatus::CANCELLED => 'Cancelled',
            BillingStatus::SUSPENDED => 'Suspended — '.($status->company->suspension_reason ?: 'tills locked'),
            BillingStatus::PAYMENT_FAILED => "Payment failed — locks on {$locks} if unpaid",
            BillingStatus::OVERDUE => "Overdue — locks on {$locks} if unpaid",
            BillingStatus::WAITING_FOR_DD => "Waiting for Direct Debit — locks on {$locks}",
            BillingStatus::TRIAL => 'Trial — '.($days === null ? 'running' : ($days === 1 ? '1 day left' : "{$days} days left")),
            BillingStatus::SETUP_FEE_DUE => 'Setup fee unpaid — tills locked',
            BillingStatus::INSTALMENT_DUE => 'Setup fee part paid — '.BillingFormat::money($status->fee->owed()).' left',
            default => 'All paid',
        };
    }

    /**
     * @return array{text: string, status: string, tone: string}
     */
    private static function setupFee(BillingStatus $status): array
    {
        $fee = $status->fee;
        $total = BillingFormat::money($fee->total);

        return match ($fee->status) {
            SetupFeeState::NONE => ['text' => $status->account->upfront_recorded_at !== null || $status->account->setup_fee_invoiced_at !== null ? 'Nothing to pay (waived or '.MoneyFormat::whole('0').')' : 'No setup fee', 'status' => 'none', 'tone' => 'neutral'],
            SetupFeeState::PAID => ['text' => "{$total} — paid".self::howPaid($status), 'status' => 'paid', 'tone' => 'success'],
            SetupFeeState::PART_PAID => [
                'text' => BillingFormat::money($fee->paid)." paid ({$fee->paidParts} of {$fee->parts} instalments)".self::howPaid($status).' — '.BillingFormat::money($fee->owed()).' left'
                    .($fee->nextDue !== null ? ', next due '.self::day($fee->nextDue) : ''),
                'status' => 'partPaid',
                'tone' => 'info',
            ],
            default => ['text' => ManualCollection::active() ? ManualStatusText::setupFeeUnpaid($status) : "{$total} — not paid yet (cash, card or bank transfer".($fee->parts > 1 ? ", in {$fee->parts} instalments" : '').')', 'status' => 'unpaid', 'tone' => 'warning'],
        };
    }

    /** " by bank transfer on 3 Sep 2026" from the payments on the setup fee invoices. */
    private static function howPaid(BillingStatus $status): string
    {
        $invoiceIds = Invoice::withoutCompanyScope()->where('company_id', $status->company->id)->where('kind', InvoiceKind::SetupFee->value)->pluck('id');
        $payments = Payment::withoutCompanyScope()->whereNull('reversed_at')
            ->whereIn('id', PaymentAllocation::withoutCompanyScope()->select('payment_id')->whereIn('invoice_id', $invoiceIds)->whereNull('released_at'))
            ->orderBy('received_at')->get();

        if ($payments->isEmpty()) {
            return '';
        }

        $methods = $payments->map(fn (Payment $payment) => $payment->method->inSentence())->unique()->values()->all();
        $last = $payments->last();

        return ' by '.implode(' and ', $methods).($payments->count() === 1 ? ' on '.self::day(BillingDates::localDate($last->received_at)) : '');
    }

    /**
     * @return array{text: string, status: string, tone: string}
     */
    private static function recurring(BillingStatus $status): array
    {
        if (ManualCollection::active()) {
            return ManualStatusText::recurring($status);
        }

        if (! $status->recurs) {
            return ['text' => 'None — no monthly or yearly fee on this plan', 'status' => 'none', 'tone' => 'neutral'];
        }

        $account = $status->account;
        $per = $status->recurring['per'] === 'per year' ? 'a year' : 'a month';
        $amount = Money::isZero($status->recurring['gross']) ? 'Nothing yet (no live tills)' : BillingFormat::money($status->recurring['gross'])." {$per}";
        $unit = $status->recurring['unitPrice'] !== null && $status->recurring['tills'] > 0
            ? ' ('.$status->recurring['tills'].' '.($status->recurring['tills'] === 1 ? 'till' : 'tills').' × '.BillingFormat::money($status->recurring['unitPrice']).(Money::isZero($status->recurring['vat']) ? '' : ' + '.Country::tax('VAT')).')'
            : '';

        [$mandate, $tone] = match (true) {
            $account->hasUsableMandate() => ['mandate '.mb_strtolower($account->gc_mandate_status?->label() ?? 'active'), 'success'],
            $account->gc_mandate_lost_at !== null => ['Direct Debit stopped ('.mb_strtolower($account->gc_mandate_status?->label() ?? 'cancelled').')', 'danger'],
            default => ['no Direct Debit set up yet', 'warning'],
        };

        return ['text' => "{$amount}{$unit} by Direct Debit — {$mandate}", 'status' => $account->hasUsableMandate() ? 'active' : 'missing', 'tone' => $tone];
    }

    /**
     * What happens next, for staff (`$portal` false) or for the business itself (the portal: "you", "your tills").
     *
     * @return array{text: string, date: string|null}|null
     */
    private static function next(BillingStatus $status, bool $portal): ?array
    {
        if (ManualCollection::active()) {
            return ManualStatusText::next($status, $portal);
        }

        $account = $status->account;
        $locks = self::day($status->locksOn);
        $date = $status->locksOn?->format('Y-m-d');
        $tills = $portal ? 'your tills' : 'the tills';
        $owed = BillingFormat::money($status->fee->owed());
        $invoice = ($status->unpaid->number ?? 'the invoice').' ('.BillingFormat::money($status->unpaid->balance ?? '0').')';
        $trialEnds = self::day($status->trialEnd !== null ? BillingDates::localDate($status->trialEnd) : null);
        $reminded = $account->mandate_reminder_for !== null && $account->mandate_deadline_at !== null && $account->mandate_reminder_for->equalTo($account->mandate_deadline_at);
        $failedOn = self::day($status->failed?->failed_at !== null ? BillingDates::localDate($status->failed->failed_at) : $status->failed?->charge_date);

        return match ($status->state) {
            BillingStatus::CANCELLED => null,
            BillingStatus::SUSPENDED => ['text' => $status->unpaid !== null
                ? ucfirst($tills)." stay locked until {$invoice} is paid. Once the payment is recorded they unlock at their next check-in."
                : ucfirst($tills).' stay locked until the Direct Debit is set up. Setting it up unlocks them at once.', 'date' => null],
            BillingStatus::PAYMENT_FAILED, BillingStatus::OVERDUE => ['text' => ($status->state === BillingStatus::PAYMENT_FAILED
                ? "The Direct Debit for {$invoice} failed on {$failedOn}. ".($portal
                    ? 'Make sure the money is in your account: the next collection takes it first, or pay it by bank transfer. '
                    : 'Retry it, or the next collection pays it first, or record a payment by hand. ')
                : ucfirst($invoice).' was due on '.self::day($status->unpaid?->due_date).'. ')
                ."If it is still unpaid on {$locks}, the account is suspended and {$tills} lock at their next check-in.", 'date' => $date],
            BillingStatus::WAITING_FOR_DD => ['text' => ($portal ? 'Set up your Direct Debit by ' : 'The owner must set up the Direct Debit by ').self::deadline($account->mandate_deadline_at)
                .($reminded ? ' (reminder email sent)' : '')
                .". If not, the account is suspended on {$locks} and {$tills} lock. Setting it up later unlocks them at once.", 'date' => $date],
            BillingStatus::TRIAL => ['text' => $status->fee->status === SetupFeeState::UNPAID
                ? "The trial ends on {$trialEnds}. Unless the setup fee ({$owed}) is paid by cash, card or bank transfer, {$tills} lock on {$locks}."
                    .($status->recurs ? ' The Direct Debit starts once the setup fee is paid.' : ' Once it is paid the licence does not expire.')
                : self::nextCollection($status, "The trial ends on {$trialEnds}."), 'date' => $date ?? $status->trialEnd?->format('Y-m-d')],
            BillingStatus::SETUP_FEE_DUE => ['text' => 'The trial is over. '.ucfirst($tills)." unlock at their next check-in once the setup fee ({$owed}) is paid by cash, card or bank transfer.", 'date' => null],
            BillingStatus::INSTALMENT_DUE => ['text' => 'Next instalment: '.BillingFormat::money($status->unpaid->balance ?? $status->fee->owed()).' due on '.self::day($status->fee->nextDue)
                .($status->recurs ? '. The Direct Debit carries on as normal: '.lcfirst(self::nextCollection($status, '')) : '. '.ucfirst($tills).' are paid up to that day; the last instalment gives the full licence.'), 'date' => $status->fee->nextDue?->format('Y-m-d')],
            default => self::paidNext($status),
        };
    }

    /**
     * @return array{text: string, date: string|null}
     */
    private static function paidNext(BillingStatus $status): array
    {
        if (! $status->recurs) {
            $until = $status->paidUntil();

            return ['text' => 'Nothing more to pay. The licence runs to '.self::day($until !== null ? BillingDates::localDate($until) : null).' and renews itself.', 'date' => null];
        }

        return ['text' => self::nextCollection($status, ''), 'date' => $status->account->gc_next_charge_date?->format('Y-m-d')];
    }

    private static function nextCollection(BillingStatus $status, string $prefix): string
    {
        $account = $status->account;
        $text = $account->hasLiveSubscription() && $account->gc_next_charge_date !== null
            ? 'Next Direct Debit: '.BillingFormat::money((string) $account->gc_subscription_amount).' on '.self::day($account->gc_next_charge_date).'.'
            : ($account->hasUsableMandate() ? 'The Direct Debit starts with the next period.' : 'Nothing to collect yet.');

        return trim("{$prefix} {$text}");
    }

    private static function deadline(?CarbonImmutable $deadline): string
    {
        return $deadline === null ? '—' : $deadline->setTimezone(Country::zone())->format('j M Y, H:i');
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function action(BillingStatus $status): array
    {
        return match ($status->state) {
            BillingStatus::SUSPENDED => $status->unpaid !== null ? ['recordPayment', 'Record payment'] : (MandateDeadline::applies($status->company, $status->account) ? ['sendDirectDebitLink', 'Send Direct Debit link'] : [null, null]),
            BillingStatus::PAYMENT_FAILED => $status->account->hasUsableMandate() ? ['retryPayment', 'Retry Direct Debit'] : ['recordPayment', 'Record payment'],
            BillingStatus::OVERDUE => ['recordPayment', 'Record payment'],
            BillingStatus::WAITING_FOR_DD => ['sendDirectDebitLink', 'Send Direct Debit link'],
            BillingStatus::TRIAL, BillingStatus::SETUP_FEE_DUE, BillingStatus::INSTALMENT_DUE => $status->fee->isSettled() ? [null, null] : ['recordSetupPayment', 'Record setup payment'],
            default => [null, null],
        };
    }
}
