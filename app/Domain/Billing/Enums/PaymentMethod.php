<?php

namespace App\Domain\Billing\Enums;

use App\Domain\Billing\Support\ManualCollection;

/**
 * How a payment reached us. Staff record cash, card (our card machine), bank transfers and anything else by hand; `online` is reserved
 * for the payment gateway (later), which records payments through the same RecordPayment action with its
 * gateway name and reference. `directDebit` is recorded by the GoCardless integration (module 1.12) only.
 *
 * Pakistan plan P5: `jazzCash` and `easypaisa` (mobile wallets) are offered only where the country profile lists them
 * (`billing.manualMethods`, PK). On a manual-collection instance every list below is the profile's methods; on GB the
 * lists are exactly the UK ones (the wallets never appear).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    /** Taken on our own card machine (owner rule 2026-10-05), recorded by hand like cash. */
    case Card = 'card';
    case BankTransfer = 'bankTransfer';
    case Other = 'other';
    case Online = 'online';
    case DirectDebit = 'directDebit';
    /** Pakistan plan P5: JazzCash mobile wallet, recorded by hand. */
    case JazzCash = 'jazzCash';
    /** Pakistan plan P5: Easypaisa mobile wallet, recorded by hand. */
    case Easypaisa = 'easypaisa';

    /** Methods of one country only: offered where the country profile lists them, never on GB. */
    private const LOCAL = [self::JazzCash, self::Easypaisa];

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Other => 'Other',
            self::Online => 'Online',
            self::DirectDebit => 'Direct Debit',
            self::JazzCash => 'JazzCash',
            self::Easypaisa => 'Easypaisa',
        };
    }

    /** The label inside a sentence: "bank transfer", "cash", but brand names as written ("JazzCash"). */
    public function inSentence(): string
    {
        return in_array($this, self::LOCAL, true) ? $this->label() : mb_strtolower($this->label());
    }

    /** Staff can record this method by hand. */
    public function isManual(): bool
    {
        return ! in_array($this, [self::Online, self::DirectDebit], true);
    }

    /**
     * @return list<self>
     */
    public static function manual(): array
    {
        if (ManualCollection::active()) {
            return ManualCollection::methods();
        }

        return array_values(array_filter(self::cases(), fn (self $method) => $method->isManual() && ! in_array($method, self::LOCAL, true)));
    }

    /**
     * How a setup fee (upfront) payment can be made: cash, card or bank transfer (PK: the profile's methods).
     *
     * @return list<self>
     */
    public static function setupFee(): array
    {
        if (ManualCollection::active()) {
            return ManualCollection::methods();
        }

        return [self::Cash, self::Card, self::BankTransfer];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function setupFeeOptions(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::setupFee());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $manualOnly = false): array
    {
        $cases = match (true) {
            $manualOnly, ManualCollection::active() => self::manual(),
            default => self::everywhere(),
        };

        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], $cases);
    }

    /**
     * Every method except the country-only ones (the UK list).
     *
     * @return list<self>
     */
    private static function everywhere(): array
    {
        $methods = [];

        foreach (self::cases() as $method) {
            if (! in_array($method, self::LOCAL, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }
}
