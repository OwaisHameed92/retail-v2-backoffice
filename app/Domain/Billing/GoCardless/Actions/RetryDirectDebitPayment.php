<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * Staff ask GoCardless to collect a failed Direct Debit payment again (the "Retry Direct Debit" button on the
 * Billing status card). GoCardless picks the date (a few working days); the payment goes back to pending, so its
 * invoice is not marked overdue while it is collected, and a new failure gets its own emails (ApplyGoCardlessPayment).
 * Only a failed payment on a working mandate; never for a demo business. Audited.
 */
class RetryDirectDebitPayment
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly ApplyGoCardlessPayment $applyPayment,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, string $paymentId): GoCardlessPayment
    {
        if (DemoBusinesses::isDemo($company)) {
            throw ValidationException::withMessages(['payment' => DemoBusinesses::goCardlessMessage()]);
        }

        $row = GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->find($paymentId);

        if (! $row instanceof GoCardlessPayment) {
            throw ValidationException::withMessages(['payment' => 'That Direct Debit payment was not found.']);
        }

        $problem = match (true) {
            $row->status !== PaymentStatus::Failed => 'Only a failed payment can be retried (this one is '.mb_strtolower($row->status->label()).').',
            ! $this->accounts->for($company)->hasUsableMandate() => 'The Direct Debit is not active, so GoCardless cannot retry. Send the setup link or record a payment.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['payment' => $problem]);
        }

        try {
            $remote = $this->client->retryPayment($row->gc_payment_id);
        } catch (GoCardlessException $exception) {
            throw ValidationException::withMessages(['payment' => $exception->getMessage()]);
        }

        $updated = $this->applyPayment->handle($company, $remote);
        $this->audit->handle('billing.dd_payment_retried', $updated, ['status' => $row->status->value], ['status' => $updated->status->value], [
            'gc_payment' => $row->gc_payment_id,
            'amount' => $row->amount,
        ], companyId: $company->id);

        return $updated;
    }
}
