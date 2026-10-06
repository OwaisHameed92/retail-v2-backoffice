<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Shared\Country\Country;
use Illuminate\Container\Container;

/**
 * Manual collection (Pakistan plan P5): on an instance whose country profile collects by hand (`billing.collection`
 * "manual", PK), every monthly or yearly period is an invoice paid by bank transfer, JazzCash, Easypaisa or cash and
 * recorded by an admin. There is no Direct Debit at all: no mandate, deadline, banner or GoCardless call.
 *
 * Every PK branch in billing asks `ManualCollection::active()`; on GB (collection "gocardless") it is false and the
 * UK code runs exactly as before. Outside a booted app (plain unit tests) it is false, i.e. GB.
 */
final class ManualCollection
{
    public static function active(): bool
    {
        $container = Container::getInstance();

        return $container->bound(Country::class) && $container->make(Country::class)->billingCollection() === 'manual';
    }

    /** Days between issuing a period invoice and its due date (`BILLING_MANUAL_DUE_DAYS`, 7). */
    public static function dueDays(): int
    {
        return max(0, (int) config('billing.manual.due_days', 7));
    }

    /**
     * The methods staff record by hand on this instance, in the profile's order (PK: bank transfer, JazzCash,
     * Easypaisa, cash).
     *
     * @return list<PaymentMethod>
     */
    public static function methods(): array
    {
        $values = Container::getInstance()->make(Country::class)->manualPaymentMethods();

        return array_values(array_filter(array_map(fn (string $value) => PaymentMethod::tryFrom($value), $values)));
    }

    /** "bank transfer, JazzCash, Easypaisa or cash". */
    public static function methodsText(): string
    {
        $labels = array_map(fn (PaymentMethod $method) => $method->inSentence(), self::methods());
        $last = array_pop($labels);

        return $labels === [] ? (string) $last : implode(', ', $labels).' or '.$last;
    }

    /**
     * How to pay, from `BILLING_PAY_*` (empty values hidden): the bank account in Pakistani style (bank name, account
     * title, IBAN) and the JazzCash / Easypaisa accounts.
     *
     * @return array{bank: list<string>, jazzCash: string|null, easypaisa: string|null}
     */
    public static function payDetails(): array
    {
        $value = fn (string $key) => ($text = trim((string) config('billing.manual.pay.'.$key, ''))) === '' ? null : $text;
        $bank = array_values(array_filter([
            $value('bank_name'),
            ($title = $value('bank_account_title')) !== null ? "Account title {$title}" : null,
            ($iban = $value('bank_iban')) !== null ? "IBAN {$iban}" : null,
        ]));

        return [
            // Without an account title or IBAN there is nothing to pay into.
            'bank' => $value('bank_account_title') === null && $value('bank_iban') === null ? [] : $bank,
            'jazzCash' => $value('jazzcash'),
            'easypaisa' => $value('easypaisa'),
        ];
    }

    /**
     * Every "how to pay" line for invoices and emails: the bank lines, then "JazzCash 0300 1234567", "Easypaisa …".
     *
     * @return list<string>
     */
    public static function payLines(): array
    {
        $details = self::payDetails();

        return array_values(array_filter([
            ...$details['bank'],
            $details['jazzCash'] !== null ? 'JazzCash '.$details['jazzCash'] : null,
            $details['easypaisa'] !== null ? 'Easypaisa '.$details['easypaisa'] : null,
        ]));
    }

    /** "Pay by bank transfer, JazzCash, Easypaisa or cash, quoting INV-000001 as the reference." */
    public static function howToPay(?string $reference): string
    {
        return 'Pay by '.self::methodsText().', quoting '.($reference ?? 'the invoice number').' as the reference.';
    }
}
