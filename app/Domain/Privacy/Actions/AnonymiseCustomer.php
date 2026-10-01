<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Customers\Queries\CustomerLedger;
use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Privacy\Support\ErasureTraces;
use App\Domain\Privacy\Support\TillSteps;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's right to erasure (module 7.7). `Customer` is hub-owned (ownership.json), so the portal anonymises it
 * and every till applies it at its next pull: name → "Anonymised customer XXXX", phone, email, address, notes and
 * card number emptied, date of birth removed, credit limit 0, inactive, `anonymisedAt` set, `rowVersion` + 1.
 * Financial records (ledger rows, sales, journals) are kept with the customer id, as the law on accounts requires.
 *
 * Marketing is blocked at once (no contact details left; MarketingConsent::allows() refuses an anonymised customer).
 * Till-owned records that carry their details (customer orders, e-receipt addresses, stored receipts) cannot be
 * changed from the portal: they are listed on the request as till steps (status tillPending). The portal's own
 * copies of their details in the activity log and email log are erased too.
 *
 * Refused while the account balance is not zero (settle it at a till first). A second call changes nothing.
 */
final class AnonymiseCustomer
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  string  $source  owner | retention
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $customerId, ?int $userId, string $source = 'owner', ?string $note = null): DataRequest
    {
        return $this->tenancy->runAs($company, fn (): DataRequest => DB::transaction(function () use ($customerId, $userId, $source, $note): DataRequest {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

            if ($customer->anonymised_at !== null) {
                throw ValidationException::withMessages(['customer' => 'This customer was already anonymised.']);
            }

            $balance = CustomerLedger::totals($customer->id)['balance'];

            if (! Money::isZero($balance)) {
                $owed = Money::compare($balance, '0') > 0 ? "owes £{$balance}" : 'is £'.Money::normalise(ltrim($balance, '-')).' in credit';
                throw ValidationException::withMessages(['customer' => "This customer {$owed}. Settle their account at a till first, then anonymise them."]);
            }

            $steps = TillSteps::forErasure($customer);
            $email = (string) $customer->email;

            $customer->forceFill([
                'name' => 'Anonymised customer '.substr($customer->id, -4),
                'phone' => '', 'email' => '', 'address' => '', 'notes' => '', 'card_no' => '', 'dob' => null,
                'credit_limit' => '0.00', 'is_active' => false, 'anonymised_at' => CarbonImmutable::now('UTC'), 'extra' => null,
                'row_version' => (int) $customer->row_version + 1,
            ])->save();

            ErasureTraces::erase($customer, $email);

            $request = DataRequest::query()->create([
                'customer_id' => $customer->id,
                'type' => DataRequestType::Erasure,
                'status' => $steps === [] ? DataRequestStatus::Completed : DataRequestStatus::TillPending,
                'source' => $source,
                'requested_by_user_id' => $userId,
                'till_steps' => $steps === [] ? null : $steps,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null,
                'completed_at' => $steps === [] ? CarbonImmutable::now('UTC') : null,
            ]);

            $this->audit->handle('customer.anonymised', $customer, null, ['anonymised' => true], [
                'request' => $request->id, 'source' => $source, 'tillSteps' => count($steps),
            ]);

            return $request;
        }));
    }
}
