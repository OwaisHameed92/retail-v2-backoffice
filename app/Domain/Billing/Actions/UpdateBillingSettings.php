<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a company's billing settings: who invoices go to, billing name and address, cycle, payment terms and
 * whether VAT is charged. Changes apply to invoices created or issued from now on.
 */
class UpdateBillingSettings
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SyncSubscription $syncSubscription,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, BillingSettingsInput $input): BillingAccount
    {
        $account = DB::transaction(function () use ($company, $input) {
            $account = $this->accounts->lock($company);
            $blank = fn (?string $value) => trim((string) $value) === '' ? null : trim((string) $value);
            $emails = array_values(array_unique(array_map(fn (string $email) => mb_strtolower(trim($email)), $input->emails)));

            $account->fill([
                'billing_name' => $blank($input->billingName),
                'billing_address' => $blank($input->billingAddress),
                'billing_emails' => $emails === [] ? null : $emails,
                'cycle' => $input->cycle,
                'payment_terms_days' => max(0, min(90, $input->paymentTermsDays)),
                'vat_applies' => $input->vatApplies,
            ]);

            [$before, $after] = AuditChanges::of($account);

            if ($after !== []) {
                $account->save();
                $this->audit->handle('billing.settings_updated', $account, $before, $after, companyId: $company->id);
            }

            return $account;
        });

        // Direct Debit (module 1.12): a new cycle or VAT setting changes what the subscription collects.
        try {
            $this->syncSubscription->handle($company, 'settings');
        } catch (GoCardlessException $exception) {
            throw ValidationException::withMessages(['cycle' => 'Saved, but GoCardless did not accept the new amount: '.$exception->getMessage()]);
        }

        return $account;
    }
}
