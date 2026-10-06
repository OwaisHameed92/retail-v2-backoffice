<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonInterface;

/**
 * The "Billing status" card sentences on a manual-collection instance (Pakistan plan P5): the same states and dates as
 * BillingStatusData, without Direct Debit. Each period is an invoice paid by hand (bank transfer, JazzCash,
 * Easypaisa, cash). BillingStatusData hands over to this class only when ManualCollection::active().
 */
final class ManualStatusText
{
    /** "Rs 1,200 — not paid yet (bank transfer, JazzCash, Easypaisa or cash, in 3 instalments)". */
    public static function setupFeeUnpaid(BillingStatus $status): string
    {
        $fee = $status->fee;

        return BillingFormat::money($fee->total).' — not paid yet ('.ManualCollection::methodsText().($fee->parts > 1 ? ", in {$fee->parts} instalments" : '').')';
    }

    /**
     * @return array{text: string, status: string, tone: string}
     */
    public static function recurring(BillingStatus $status): array
    {
        if (! $status->recurs) {
            return ['text' => 'None — no monthly or yearly fee on this plan', 'status' => 'none', 'tone' => 'neutral'];
        }

        $per = $status->recurring['per'] === 'per year' ? 'a year' : 'a month';
        $amount = Money::isZero($status->recurring['gross']) ? 'Nothing yet (no live tills)' : BillingFormat::money($status->recurring['gross'])." {$per}";
        $unit = $status->recurring['unitPrice'] !== null && $status->recurring['tills'] > 0
            ? ' ('.$status->recurring['tills'].' '.($status->recurring['tills'] === 1 ? 'till' : 'tills').' × '.BillingFormat::money($status->recurring['unitPrice']).(Money::isZero($status->recurring['vat']) ? '' : ' + '.Country::tax('VAT')).')'
            : '';
        $every = $status->recurring['per'] === 'per year' ? 'each year' : 'each month';

        return ['text' => "{$amount}{$unit} — an invoice {$every}, paid by ".ManualCollection::methodsText(), 'status' => 'invoiced', 'tone' => 'neutral'];
    }

    /**
     * What happens next, for staff (`$portal` false) or the business itself (the portal: "you", "your tills").
     *
     * @return array{text: string, date: string|null}|null
     */
    public static function next(BillingStatus $status, bool $portal): ?array
    {
        $locks = self::day($status->locksOn);
        $date = $status->locksOn?->format('Y-m-d');
        $tills = $portal ? 'your tills' : 'the tills';
        $methods = ManualCollection::methodsText();
        $owed = BillingFormat::money($status->fee->owed());
        $invoice = ($status->unpaid->number ?? 'the invoice').' ('.BillingFormat::money($status->unpaid->balance ?? '0').')';
        $trialEnds = self::day($status->trialEnd !== null ? BillingDates::localDate($status->trialEnd) : null);

        return match ($status->state) {
            BillingStatus::CANCELLED => null,
            BillingStatus::SUSPENDED => ['text' => $status->unpaid !== null
                ? ucfirst($tills)." stay locked until {$invoice} is paid. Once the payment is recorded they unlock at their next check-in."
                : ucfirst($tills).' stay locked until the suspension is lifted.', 'date' => null],
            BillingStatus::PAYMENT_FAILED, BillingStatus::OVERDUE => ['text' => ucfirst($invoice).' was due on '.self::day($status->unpaid?->due_date).'. '
                .($portal ? "Pay it by {$methods}. " : 'Record the payment as soon as it arrives. ')
                ."If it is still unpaid on {$locks}, the account is suspended and {$tills} lock at their next check-in.", 'date' => $date],
            BillingStatus::TRIAL => ['text' => $status->fee->status === SetupFeeState::UNPAID
                ? "The trial ends on {$trialEnds}. Unless the setup fee ({$owed}) is paid by {$methods}, {$tills} lock on {$locks}."
                    .($status->recurs ? ' The '.($status->recurring['per'] === 'per year' ? 'yearly' : 'monthly').' invoices start once the setup fee is paid.' : ' Once it is paid the licence does not expire.')
                : trim("The trial ends on {$trialEnds}. ".self::nextInvoice($status)), 'date' => $date ?? $status->trialEnd?->format('Y-m-d')],
            BillingStatus::SETUP_FEE_DUE => ['text' => 'The trial is over. '.ucfirst($tills)." unlock at their next check-in once the setup fee ({$owed}) is paid by {$methods}.", 'date' => null],
            BillingStatus::INSTALMENT_DUE => ['text' => 'Next instalment: '.BillingFormat::money($status->unpaid->balance ?? $status->fee->owed()).' due on '.self::day($status->fee->nextDue)
                .($status->recurs ? '. '.self::nextInvoice($status) : '. '.ucfirst($tills).' are paid up to that day; the last instalment gives the full licence.'), 'date' => $status->fee->nextDue?->format('Y-m-d')],
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

        return ['text' => self::nextInvoice($status), 'date' => self::openInvoice($status)?->due_date?->format('Y-m-d')];
    }

    /** "Invoice INV-000003 (Rs 2,500) is due on 1 Nov 2026." or "The next invoice is emailed 7 days before 1 Nov 2026, when the tills are paid to." */
    private static function nextInvoice(BillingStatus $status): string
    {
        $open = self::openInvoice($status);

        if ($open !== null) {
            return 'Invoice '.$open->number.' ('.BillingFormat::money($open->balance).') is due on '.self::day($open->due_date).'.';
        }

        if (Money::isZero($status->recurring['gross'])) {
            return 'Nothing to invoice yet.';
        }

        $start = BillingPeriod::nextStart($status->company, $status->now);
        $days = max(0, (int) config('billing.generate.days_before', 7));

        return 'The next invoice ('.BillingFormat::money($status->recurring['gross']).') is emailed '.($days === 1 ? '1 day' : "{$days} days").' before '.self::day($start).'.';
    }

    /** The oldest unpaid period invoice (not a setup fee instalment), not yet late. */
    private static function openInvoice(BillingStatus $status): ?Invoice
    {
        return Invoice::withoutCompanyScope()->where('company_id', $status->company->id)->open()
            ->where('kind', '!=', InvoiceKind::SetupFee->value)->orderBy('due_date')->orderBy('sequence')->first();
    }

    private static function day(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->format('j M Y');
    }
}
