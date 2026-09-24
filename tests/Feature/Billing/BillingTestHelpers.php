<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\IssueCreditNote;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Actions\RunBilling;
use App\Domain\Billing\Actions\SendTrialEmails;
use App\Domain\Billing\Actions\VoidInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Data\RecordedPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the module 1.8 tests. Use with
 * `uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class)`.
 */
trait BillingTestHelpers
{
    /**
     * A tenant whose tills are all activated and paid until the end of `$paidUntil` (London), on the Standard
     * plan at £25.00 a month / £250.00 a year per till.
     */
    public function payingTenant(string $name = 'Khan Mini Mart', int $tills = 2, string $code = 'LDS', string $paidUntil = '2026-10-31'): Company
    {
        $plan = $this->standardPlan();
        $plan->forceFill(['price_per_till_monthly' => '25.00', 'price_per_till_yearly' => '250.00'])->save();

        $company = $this->licensedTenant($name, $tills, $code);

        foreach ($this->licencesOf($company) as $licence) {
            $this->activate($licence, CarbonImmutable::now()->subDays(40));
            $licence->forceFill(['expires_at' => BillingDates::endOfDay(BillingDates::date($paidUntil)), 'grace_days' => $plan->grace_days])->save();
        }

        return $company->refresh();
    }

    /**
     * @return list<Licence>
     */
    public function licencesOf(Company $company): array
    {
        return Licence::withoutCompanyScope()->where('company_id', $company->id)->live()->orderBy('created_at')->get()->all();
    }

    public function draftFor(Company $company, ?NewInvoice $input = null): Invoice
    {
        return app(GenerateInvoice::class)->handle($company, $input ?? new NewInvoice);
    }

    public function issuedFor(Company $company, ?NewInvoice $input = null): Invoice
    {
        return app(IssueInvoice::class)->handle($this->draftFor($company, $input));
    }

    /**
     * @param  array<string, string>|null  $allocations
     */
    public function pay(Company $company, string $amount, ?array $allocations = null, PaymentMethod $method = PaymentMethod::Cash): RecordedPayment
    {
        return app(RecordPayment::class)->handle($company, new NewPayment(
            method: $method,
            amount: $amount,
            receivedAt: CarbonImmutable::now(),
            reference: 'Cash at shop',
            allocations: $allocations,
        ));
    }

    /** A bank transfer received at a London wall-clock time (allocated oldest first, the rest is credit). */
    public function payAt(Company $company, string $amount, string $london): RecordedPayment
    {
        return app(RecordPayment::class)->handle($company, new NewPayment(
            method: PaymentMethod::BankTransfer,
            amount: $amount,
            receivedAt: CarbonImmutable::parse($london, 'Europe/London'),
        ));
    }

    public function setVat(bool $enabled, bool $companyApplies = true, ?Company $company = null): void
    {
        config(['billing.vat.enabled' => $enabled, 'billing.vat.rate' => '20.00', 'billing.vat.number' => 'GB123456789']);

        if ($company !== null) {
            $account = app(BillingAccounts::class)->for($company);
            $account->vat_applies = $companyApplies;
            $account->save();
        }
    }

    public function fresh(Invoice $invoice): Invoice
    {
        return Invoice::withoutCompanyScope()->findOrFail($invoice->id);
    }

    public function licenceFresh(Licence $licence): Licence
    {
        return Licence::withoutCompanyScope()->findOrFail($licence->id);
    }

    /** "2026-11-30 23:59:59" London as a UTC string. */
    public function londonEnd(string $date): string
    {
        return BillingDates::endOfDay(BillingDates::date($date))->toDateTimeString();
    }

    /** Set the test clock to a London wall-clock time, e.g. "2026-11-01 06:00". */
    public function atLondon(string $datetime): void
    {
        $this->travelTo(CarbonImmutable::parse($datetime, 'Europe/London'));
    }

    public function billingAccountOf(Company $company): BillingAccount
    {
        return app(BillingAccounts::class)->for($company->refresh());
    }

    /** Billing emails for invoices (instead of the owners). */
    public function useBillingEmails(Company $company, array $emails): void
    {
        $account = $this->billingAccountOf($company);
        $account->billing_emails = $emails;
        $account->save();
    }

    /**
     * @return array{invoicesCreated: int, invoicesOverdue: int, companiesSuspended: int, trialReminders: int, trialEnded: int}
     */
    public function runBilling(): array
    {
        return app(RunBilling::class)->handle(CarbonImmutable::now());
    }

    /**
     * billing:run on the morning (06:00) of a London date.
     *
     * @return array{invoicesCreated: int, invoicesOverdue: int, companiesSuspended: int, trialReminders: int, trialEnded: int}
     */
    public function runBillingOn(string $date): array
    {
        $this->atLondon($date.' 06:00');

        return $this->runBilling();
    }

    /**
     * The trial emails step of billing:run alone, at a London date and time.
     *
     * @return array{reminders: int, ended: int}
     */
    public function trialEmailsAt(string $datetime): array
    {
        $this->atLondon($datetime);

        return app(SendTrialEmails::class)->handle(CarbonImmutable::now());
    }

    /**
     * One of each for billing:run (clock at 1 Oct 2026): a company whose tills run out on 31 Oct, a company with
     * an invoice issued now (due 8 Oct) and a trial ending 28 Oct 10:00 London.
     *
     * @return array{0: Company, 1: Company, 2: Invoice, 3: Company}
     */
    public function billingRunScenario(): array
    {
        $due = $this->payingTenant('Due Stores', 2, 'DUE');
        $late = $this->payingTenant('Late Payers', 1, 'LTE');
        $lateInvoice = $this->issuedFor($late);
        $trial = $this->trialTenant('Trial Stores', 1, '2026-10-28 10:00', 'TRL');

        return [$due, $late, $lateInvoice, $trial];
    }

    /**
     * @return array{0: Invoice, 1: Invoice|null}
     */
    public function voidIt(Invoice $invoice, string $reason = 'Wrong till count', bool $redraft = false): array
    {
        return app(VoidInvoice::class)->handle($invoice, $reason, $redraft);
    }

    public function creditIt(Invoice $invoice, string $amount, string $reason = 'Goodwill for downtime'): CreditNote
    {
        return app(IssueCreditNote::class)->handle($invoice, $amount, $reason);
    }

    public function companyFresh(Company $company): Company
    {
        return Company::withTrashed()->findOrFail($company->id);
    }

    /** Last value of a billing_sequences row ("invoice", "payment", "credit_note"). */
    public function sequenceValue(string $name): int
    {
        return (int) DB::table('billing_sequences')->where('name', $name)->value('last_value');
    }

    /**
     * @return list<string>
     */
    public function issuedNumbers(): array
    {
        return Invoice::withoutCompanyScope()->whereNotNull('number')->orderBy('sequence')->pluck('number')->all();
    }

    /**
     * A tenant on trial: every till activated `$activatedDaysAgo` days ago, trials ending at the same instant
     * (`$trialEndsAt`, London wall clock), never paid (no expires_at).
     */
    public function trialTenant(string $name = 'Trial Stores', int $tills = 1, string $trialEndsAt = '2026-10-26 10:00', string $code = 'TRL'): Company
    {
        $plan = $this->standardPlan();
        $plan->forceFill(['price_per_till_monthly' => '25.00', 'price_per_till_yearly' => '250.00'])->save();
        $company = $this->licensedTenant($name, $tills, $code);
        $ends = CarbonImmutable::parse($trialEndsAt, 'Europe/London')->utc();

        foreach ($this->licencesOf($company) as $licence) {
            $this->activate($licence, $ends->subDays(7));
            $licence->forceFill(['trial_ends_at' => $ends])->save();
        }

        return $company->refresh();
    }

    /**
     * The child process: boots the app against the temp database only, then either sets it up (migrate, a plan,
     * tenants, drafts) or issues the given drafts, retrying when SQLite reports the database is locked.
     */
    public function concurrencyWorkerScript(): string
    {
        return <<<'PHP'
<?php

use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Enums\Nation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

[, $base, $databaseFile, $mode, $out] = $argv;
$rest = array_slice($argv, 5);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$configured = (string) config('database.connections.sqlite.database');

if (config('database.default') !== 'sqlite'
    || realpath($configured) === false
    || realpath($configured) !== realpath($databaseFile)
    || realpath($configured) === realpath(database_path('database.sqlite'))) {
    fwrite(STDERR, "Refusing to run against [{$configured}].\n");
    exit(97);
}

config([
    'database.connections.sqlite.busy_timeout' => 20000,
    'database.connections.sqlite.journal_mode' => 'wal',
    // A throwaway database: no fsync needed (locking is unchanged).
    'database.connections.sqlite.synchronous' => 'off',
]);
DB::purge('sqlite');

if ($mode === 'setup') {
    Artisan::call('migrate', ['--force' => true]);

    Plan::factory()->create([
        'name' => 'Standard', 'code' => 'standard',
        'price_per_till_monthly' => '25.00', 'price_per_till_yearly' => '250.00',
    ]);

    $count = (int) $rest[0];
    $ids = [];

    for ($c = 1; count($ids) < $count; $c++) {
        $company = app(CreateTenant::class)->handle(new NewTenant(
            company: new CompanyDetails(name: "Shop {$c}", legalName: "Shop {$c} Ltd", email: "shop{$c}@example.test"),
            branch: new BranchDetails(code: 'LDS', name: 'Leeds', nation: Nation::England, address: '1 High Street, Leeds'),
            tills: 2,
            ownerName: 'Owner '.$c,
            ownerEmail: "owner{$c}@example.test",
            status: CompanyStatus::Trial,
        ));
        app(BillingAccounts::class)->for($company)->save();

        for ($i = 0; $i < 12 && count($ids) < $count; $i++) {
            $ids[] = app(GenerateInvoice::class)->handle($company, new NewInvoice(allowOverlap: true))->id;
        }
    }

    file_put_contents($out, json_encode($ids));
    exit(0);
}

// mode "issue": start time, comma-separated draft ids
$startAt = (float) $rest[0];
$ids = array_filter(explode(',', $rest[1] ?? ''));
DB::connection()->getPdo();

while (microtime(true) < $startAt) {
    usleep(1000);
}

$numbers = [];
$retries = 0;

foreach ($ids as $id) {
    for ($attempt = 1; ; $attempt++) {
        try {
            $invoice = Invoice::withoutCompanyScope()->findOrFail($id);
            $numbers[$id] = app(IssueInvoice::class)->handle($invoice)->number;

            break;
        } catch (Throwable $e) {
            $locked = str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'database table is locked');

            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if (! $locked || $attempt >= 300) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
                exit(1);
            }

            $retries++;
            usleep(random_int(2000, 25000));
        }
    }
}

file_put_contents($out, json_encode(['numbers' => $numbers, 'retries' => $retries]));
exit(0);
PHP;
    }
}
