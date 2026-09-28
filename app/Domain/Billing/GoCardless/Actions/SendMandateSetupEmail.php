<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Send Direct Debit setup email": the owners get our signed link (valid `billing.direct_debit.setup_link_days`)
 * that opens the GoCardless setup page. Completion arrives by webhook. Audited. Returns how many were queued.
 */
class SendMandateSetupEmail
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly DirectDebitMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company): int
    {
        $account = $this->accounts->for($company);

        $problem = match (true) {
            ! $this->client->enabled() => 'GoCardless is not set up yet: add GOCARDLESS_ACCESS_TOKEN to the server settings.',
            ! $account->isDirectDebit() => "{$company->name} pays upfront. Switch it to Direct Debit first.",
            $company->isCancelled() => "{$company->name} is cancelled.",
            $account->hasUsableMandate() => "{$company->name} already has a working Direct Debit.",
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['direct_debit' => $problem]);
        }

        $sent = $this->mailer->setup($company, $account);

        if ($sent === 0) {
            throw ValidationException::withMessages(['direct_debit' => "{$company->name} has no active owner to email. Add an owner first."]);
        }

        DB::transaction(function () use ($company, $sent) {
            $account = $this->accounts->lock($company);
            $account->gc_setup_sent_at = CarbonImmutable::now();
            $account->save();

            $this->audit->handle('billing.dd_setup_sent', $account, null, null, ['recipients' => $sent], companyId: $company->id);
        });

        return $sent;
    }
}
