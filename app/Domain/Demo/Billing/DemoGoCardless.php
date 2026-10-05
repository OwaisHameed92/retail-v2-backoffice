<?php

namespace App\Domain\Demo\Billing;

use App\Domain\Billing\GoCardless\Actions\ApplyGoCardlessPayment;
use App\Domain\Billing\GoCardless\Actions\ApplyMandate;
use App\Domain\Billing\GoCardless\Actions\ApplySubscription;
use App\Domain\Billing\GoCardless\Data\GcMandate;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Data\GcSubscription;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plays GoCardless' part for a demo business, WITHOUT calling GoCardless: it hands our real webhook actions
 * (ApplyMandate, ApplySubscription, ApplyGoCardlessPayment) what GoCardless would send, with made-up `DEMO-…` ids, so
 * the mandate, subscription, payments, invoices and licence dates are exactly what a real customer would get.
 * Refuses anything that is not a demo business.
 */
final class DemoGoCardless
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ApplyMandate $applyMandate,
        private readonly ApplySubscription $applySubscription,
        private readonly ApplyGoCardlessPayment $applyPayment,
    ) {}

    /** The owner finished the Direct Debit setup: an active mandate. */
    public function mandate(Company $company): string
    {
        $this->demoOnly($company);
        $id = self::id('MD');
        $this->applyMandate->handle($company, new GcMandate($id, MandateStatus::Active, self::id('CU'), CarbonImmutable::now()->addWeekdays(3)->format('Y-m-d'), ['company_id' => $company->id]));

        return $id;
    }

    /** GoCardless created the monthly subscription for what the business owes, first charge on `$firstCharge`. */
    public function subscription(Company $company, CarbonImmutable $firstCharge): string
    {
        $this->demoOnly($company);
        $account = $this->accounts->for($company);
        $id = self::id('SB');

        DB::transaction(function () use ($company, $id) {
            $locked = $this->accounts->lock($company);
            $locked->gc_subscription_id = $id;
            $locked->save();
        });

        $this->move($company, $id, $firstCharge, SubscriptionAmount::for($company, $account)['gross'], $account->cycle->value, (string) $account->gc_mandate_id);

        return $id;
    }

    /** The subscription's next charge date moved on (after GoCardless created a payment). */
    public function nextCharge(Company $company, CarbonImmutable $date): void
    {
        $account = $this->accounts->for($company);
        $this->move($company, (string) $account->gc_subscription_id, $date, (string) $account->gc_subscription_amount, $account->cycle->value, (string) $account->gc_mandate_id);
    }

    /** GoCardless created a subscription payment charged on `$chargeDate` (status pending submission). */
    public function payment(Company $company, CarbonImmutable $chargeDate): GoCardlessPayment
    {
        $this->demoOnly($company);
        $account = $this->accounts->for($company);

        return $this->applyPayment->handle($company, new GcPayment(
            id: self::id('PM'),
            amountPence: Pence::fromPounds((string) $account->gc_subscription_amount),
            status: PaymentStatus::PendingSubmission,
            chargeDate: $chargeDate->format('Y-m-d'),
            mandateId: $account->gc_mandate_id,
            subscriptionId: $account->gc_subscription_id,
            description: 'Subscription',
            metadata: ['company_id' => $company->id],
        ));
    }

    /** A webhook moved the payment on (confirmed, failed…). */
    public function status(Company $company, GoCardlessPayment $row, PaymentStatus $status, ?string $reason = null): GoCardlessPayment
    {
        $this->demoOnly($company);

        return $this->applyPayment->handle($company, new GcPayment(
            id: $row->gc_payment_id,
            amountPence: Pence::fromPounds($row->amount),
            status: $status,
            chargeDate: $row->charge_date?->format('Y-m-d'),
            mandateId: $row->gc_mandate_id,
            subscriptionId: $row->gc_subscription_id,
            description: $row->description,
            metadata: ['company_id' => $company->id],
        ), $reason);
    }

    private function move(Company $company, string $id, CarbonImmutable $date, string $gross, string $cycle, string $mandateId): void
    {
        $this->applySubscription->handle($company, new GcSubscription($id, Pence::fromPounds($gross), $cycle, SubscriptionStatus::Active, $mandateId, $date->format('Y-m-d'), ['company_id' => $company->id]));
    }

    private function demoOnly(Company $company): void
    {
        if (! DemoBusinesses::isDemo($company)) {
            throw new \LogicException("{$company->name} is not a demo business.");
        }
    }

    private static function id(string $kind): string
    {
        return DemoBusinesses::ID_PREFIX.$kind.'-'.Str::upper(Str::random(12));
    }
}
