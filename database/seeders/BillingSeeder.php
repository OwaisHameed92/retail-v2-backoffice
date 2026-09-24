<?php

namespace Database\Seeders;

use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\MarkOverdueInvoices;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Module 1.8 demo billing (local and testing only), built with the real actions so numbers, balances and the
 * audit log are consistent. No email is sent (invoices are issued without sending).
 *
 * - Khan Mini Mart: a paid invoice from last month (cash), an overdue invoice with a part payment, and a draft
 *   for the next period made by the "billing run".
 * - Corner Shop Express: a paying customer with an invoice due this week and £10.00 of credit.
 */
class BillingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $now = CarbonImmutable::now();
        $khan = Company::query()->where('name', 'Khan Mini Mart')->first();

        try {
            if ($khan !== null) {
                $this->khan($khan, $now);
            }

            $plan = Plan::query()->where('code', 'standard')->first();

            if ($plan !== null) {
                $this->cornerShop($plan, $now);
            }
        } finally {
            $this->at(null);
        }

        app(MarkOverdueInvoices::class)->handle($now);
    }

    private function khan(Company $company, CarbonImmutable $now): void
    {
        // Last month (40 to 10 days ago): issued 45 days ago, paid late in cash 9 days ago. Its period is over, so
        // nothing is renewed and the demo licences keep their states.
        $this->at($now->subDays(45));
        $paid = $this->issue($company, new NewInvoice(periodStart: BillingDates::today($now)->subDays(40)));

        // This month (from 9 days ago): issued 20 days ago, due 13 days ago, £20.00 paid on account 5 days ago.
        // Recording the rest renews the tills to the end of the period.
        $this->at($now->subDays(20));
        $overdue = $this->issue($company, new NewInvoice(periodStart: $paid->period_end->addDay(), notes: 'Thank you for your business.'));
        $this->at($now->subDays(9));
        $this->pay($company, $paid->total, PaymentMethod::Cash, 'Collected at the shop by Sam', [$paid->id => $paid->total]);
        $this->at($now->subDays(5));
        $this->pay($company, '20.00', PaymentMethod::BankTransfer, 'FPS KHAN MINI MART', [$overdue->id => '20.00']);

        // Next period: a draft the billing run would make.
        $this->at(null);
        app(GenerateInvoice::class)->handle($company, new NewInvoice(periodStart: $overdue->period_end->addDay(), auto: true));
    }

    private function cornerShop(Plan $plan, CarbonImmutable $now): void
    {
        if (Company::query()->where('name', 'Corner Shop Express')->exists()) {
            return;
        }

        $company = Company::factory()->create(['name' => 'Corner Shop Express', 'status' => CompanyStatus::Active, 'address' => '3 Market Street, Wakefield WF1 1DH']);
        $company->plan_id = $plan->id;
        $company->save();

        User::factory()->withCompany($company)->create(['name' => 'Priya Shah', 'email' => 'owner@cornershop.test']);
        $branch = Branch::factory()->forCompany($company)->create(['code' => 'WKF', 'name' => 'Wakefield', 'address' => '3 Market Street, Wakefield WF1 1DH']);

        foreach ([1, 2] as $i) {
            $register = Register::factory()->forBranch($branch)->create(['code' => Register::codeFor($i), 'name' => 'Till '.$i, 'is_main_till' => $i === 1]);
            $licence = app(IssueLicence::class)->handle($register, $plan)->licence;

            Licence::withoutCompanyScope()->whereKey($licence->id)->firstOrFail()->forceFill([
                'status' => LicenceStatus::Active,
                'activated_at' => $now->subMonths(3),
                'trial_ends_at' => $now->subMonths(3)->addDays($plan->trial_days),
                'expires_at' => BillingDates::endOfDay(BillingDates::today()->addDays(5)),
                'grace_days' => $plan->grace_days,
                'device_id' => 'DEMO-WKF-0'.$i,
                'device_name' => 'WAKEFIELD-TILL-0'.$i,
                'bound_at' => $now->subMonths(3),
            ])->save();
        }

        // Issued 2 days ago, due in 5 days; the last payment left £10.00 of credit (used on the next invoice).
        $this->at($now->subDays(9));
        $this->pay($company, '10.00', PaymentMethod::Cash, 'Rounded up at the counter');
        $this->at($now->subDays(2));
        $this->issue($company);
    }

    private function issue(Company $company, NewInvoice $input = new NewInvoice): Invoice
    {
        $draft = app(GenerateInvoice::class)->handle($company, $input);

        return app(IssueInvoice::class)->handle($draft, send: false);
    }

    /**
     * @param  array<string, string>|null  $allocations
     */
    private function pay(Company $company, string $amount, PaymentMethod $method, string $reference, ?array $allocations = null): void
    {
        app(RecordPayment::class)->handle($company, new NewPayment(
            method: $method,
            amount: $amount,
            receivedAt: CarbonImmutable::now(),
            reference: $reference,
            allocations: $allocations,
        ));
    }

    /** Pretend it is `$moment` (null = the real now) so dates and the audit log read naturally. */
    private function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
