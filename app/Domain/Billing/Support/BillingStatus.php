<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Where one business stands with its billing, in one word (the state) and plain sentences, for the "Billing status"
 * card (admin Billing tab and the portal) and the admin overview filter. The rules are those of docs/billing-flow.md;
 * dates are the ones the daily billing run (06:00 London) acts on. In order of urgency:
 *
 * cancelled → suspended → paymentFailed / overdue → waitingForDirectDebit → trial / setupFeeDue → instalmentDue →
 * paid. The sentences are built by BillingStatusText.
 */
final readonly class BillingStatus
{
    public const CANCELLED = 'cancelled';

    public const SUSPENDED = 'suspended';

    public const PAYMENT_FAILED = 'paymentFailed';

    public const OVERDUE = 'overdue';

    public const WAITING_FOR_DD = 'waitingForDirectDebit';

    public const TRIAL = 'trial';

    public const SETUP_FEE_DUE = 'setupFeeDue';

    public const INSTALMENT_DUE = 'instalmentDue';

    public const PAID = 'paid';

    /** The overview filter groups: state => group. */
    public const GROUPS = [
        self::PAID => 'paid',
        self::TRIAL => 'trial',
        self::WAITING_FOR_DD => 'waitingForDirectDebit',
        self::PAYMENT_FAILED => 'overdue',
        self::OVERDUE => 'overdue',
        self::SETUP_FEE_DUE => 'setupDue',
        self::INSTALMENT_DUE => 'setupDue',
        self::SUSPENDED => 'suspended',
        self::CANCELLED => 'cancelled',
    ];

    /**
     * @param  array{gross: string, unitPrice: string|null, tills: int, per: string, vat: string}  $recurring
     */
    public function __construct(
        public string $state,
        public Company $company,
        public BillingAccount $account,
        public SetupFeeState $fee,
        public bool $recurs,
        public array $recurring,
        public CarbonImmutable $now,
        /** The day the tills lock (suspension, or the end of the trial and its grace days) if nothing changes. */
        public ?CarbonImmutable $locksOn = null,
        public ?CarbonImmutable $trialEnd = null,
        public ?Invoice $unpaid = null,
        public ?GoCardlessPayment $failed = null,
    ) {}

    public static function for(Company $company, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $account = app(BillingAccounts::class)->for($company);
        $fee = SetupFeeState::for($company, $account);
        $amount = SubscriptionAmount::for($company, $account, $now);
        $type = ApplySetupFeeTerms::planType($company);
        $recurs = $type?->recurs() ?? ! Money::isZero($amount['gross']);
        $recurring = ['gross' => $amount['gross'], 'unitPrice' => $amount['unitPrice'], 'tills' => $amount['tills'], 'per' => $amount['cycle']->per(), 'vat' => $amount['vat']];
        $make = fn (string $state, ...$more) => new self($state, $company, $account, $fee, $recurs, $recurring, $now, ...$more);

        if ($company->status === CompanyStatus::Cancelled) {
            return $make(self::CANCELLED);
        }

        $unpaid = self::oldestUnpaid($company);

        if ($company->status === CompanyStatus::Suspended) {
            return $make(self::SUSPENDED, unpaid: $unpaid);
        }

        $late = $unpaid !== null && ($unpaid->status === InvoiceStatus::Overdue || ($unpaid->due_date !== null && $unpaid->due_date->lessThan(BillingDates::today($now))));
        $failed = $unpaid !== null ? self::failedCollection($unpaid) : null;

        if ($failed !== null || $late) {
            /** @var Invoice $unpaid */
            return $make($failed !== null ? self::PAYMENT_FAILED : self::OVERDUE, locksOn: self::notBeforeNextRun(self::invoiceLockDay($unpaid), $now), unpaid: $unpaid, failed: $failed);
        }

        if (MandateDeadline::applies($company, $account) && $account->mandate_deadline_at !== null) {
            return $make(self::WAITING_FOR_DD, locksOn: self::notBeforeNextRun(self::firstRunAt($account->mandate_deadline_at), $now));
        }

        $trialEnd = self::trialEnd($company);

        if ($fee->status === SetupFeeState::UNPAID) {
            $trialOn = $trialEnd !== null && $trialEnd->greaterThan($now);

            $grace = CompanyPricing::for($company, $account)->plan->trial_grace_days ?? 0;

            return $make($trialOn ? self::TRIAL : self::SETUP_FEE_DUE, locksOn: $trialOn ? BillingDates::localDate($trialEnd->addDays(max(0, $grace))) : null, trialEnd: $trialEnd);
        }

        if ($fee->status === SetupFeeState::PART_PAID) {
            return $make(self::INSTALMENT_DUE, unpaid: self::nextInstalment($company));
        }

        $neverPaid = ! Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('status', InvoiceStatus::Paid->value)->exists();

        if ($company->status === CompanyStatus::Trial && $neverPaid && $trialEnd !== null && $trialEnd->greaterThan($now)) {
            return $make(self::TRIAL, trialEnd: $trialEnd);
        }

        return $make(self::PAID);
    }

    public function group(): string
    {
        return self::GROUPS[$this->state];
    }

    /** Whole days left before `$locksOn` / the trial end (0 on the day). */
    public function daysLeft(): ?int
    {
        $end = $this->state === self::TRIAL ? $this->trialEnd : $this->locksOn;

        if ($end === null) {
            return null;
        }

        $hours = $this->now->diffInHours($this->state === self::TRIAL ? $end : BillingDates::endOfDay($end)->subDay(), false);

        return $hours <= 0 ? 0 : (int) ceil($hours / 24);
    }

    /** The paid end of the live tills (setup-only full licence, or the last period paid). */
    public function paidUntil(): ?CarbonImmutable
    {
        $expiry = RenewCompanyLicences::renewable($this->company)->whereNotNull('expires_at')->min('expires_at');

        return is_string($expiry) && $expiry !== '' ? CarbonImmutable::parse($expiry, 'UTC') : null;
    }

    private static function oldestUnpaid(Company $company): ?Invoice
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)->open()
            ->whereNotIn('kind', InvoiceKind::setupFeeValues())
            ->orderBy('due_date')->orderBy('sequence')->first();
    }

    private static function nextInstalment(Company $company): ?Invoice
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)->open()
            ->where('kind', InvoiceKind::SetupFee->value)->orderBy('due_date')->orderBy('sequence')->first();
    }

    /** The Direct Debit payment of an unpaid invoice, when its latest attempt failed or was charged back. */
    private static function failedCollection(Invoice $invoice): ?GoCardlessPayment
    {
        $latest = GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $invoice->id)->latest('created_at')->latest('id')->first();

        return $latest !== null && $latest->status->isProblem() ? $latest : null;
    }

    /**
     * The day billing:run suspends for this invoice (SuspendForUnpaidInvoices): due more than `suspend_after_days`
     * ago, and reopened (chargeback, failure after collection) more than that ago.
     */
    public static function invoiceLockDay(Invoice $invoice): ?CarbonImmutable
    {
        $days = max(0, (int) config('billing.suspend_after_days', 14));
        $day = $invoice->due_date?->addDays($days + 1);

        if ($invoice->reopened_at !== null) {
            $reopened = self::firstRunAt($invoice->reopened_at->addDays($days), strictlyAfter: true);
            $day = $day === null || $reopened->greaterThan($day) ? $reopened : $day;
        }

        return $day;
    }

    /** A lock day already gone (the run has not acted yet) is the next run's day. */
    private static function notBeforeNextRun(?CarbonImmutable $day, CarbonImmutable $now): ?CarbonImmutable
    {
        $next = self::firstRunAt($now, strictlyAfter: true);

        return $day !== null && $day->lessThan($next) ? $next : $day;
    }

    /** The London day of the first daily billing run (06:00) at or after an instant. */
    public static function firstRunAt(CarbonImmutable $instant, bool $strictlyAfter = false): CarbonImmutable
    {
        $london = $instant->setTimezone(Country::zone());
        $run = $london->setTime(6, 0);
        $late = $strictlyAfter ? $london->greaterThanOrEqualTo($run) : $london->greaterThan($run);

        return BillingDates::date(($late ? $run->addDay() : $run)->format('Y-m-d'));
    }

    /** The trial end: the first trial licence's (activated tills), else the company's own trial date. */
    public static function trialEnd(Company $company): ?CarbonImmutable
    {
        $licence = RenewCompanyLicences::renewable($company)->whereNull('expires_at')->whereNotNull('trial_ends_at')->min('trial_ends_at');

        if (is_string($licence) && $licence !== '') {
            return CarbonImmutable::parse($licence, 'UTC');
        }

        return $company->trial_ends_at !== null ? CarbonImmutable::instance($company->trial_ends_at) : null;
    }
}
