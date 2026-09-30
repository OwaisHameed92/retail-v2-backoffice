<?php

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Support\CustomerFields;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds or edits a customer on the portal (module 4.4). `Customer` is hub-owned: the save goes through the model
 * (HubOwnedRow), so every till receives it at its next pull, and the row keeps its ULID.
 *
 * Only the portal-owned details are written (`CustomerFields::EDITABLE`): name, phone, email, address, date of birth,
 * card (loyalty) number, credit limit, tier, notes and active. `balance` / `points` are never written here: they are
 * the ledger's sum (contract §10.1, RecomputeCustomerBalances); a new customer starts at 0 / 0.
 *
 * A save that changes nothing writes nothing (no pull version, no audit); an edit raises `row_version` by one, as the
 * till does. The card number is unique in the business. An anonymised customer cannot be edited.
 *
 *     app(SaveCustomer::class)->handle($company, null, ['name' => 'Aisha Rahman', 'card_no' => 'LC000123']);
 */
final class SaveCustomer
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  snake_case columns (a subset of CustomerFields::EDITABLE)
     *
     * @throws ValidationException
     */
    public function handle(Company $company, ?string $customerId, array $data): Customer
    {
        return $this->tenancy->runAs($company, fn (): Customer => DB::transaction(function () use ($customerId, $data): Customer {
            $customer = $customerId === null
                ? (new Customer)->forceFill(CustomerFields::defaults())
                : Customer::query()->lockForUpdate()->findOrFail($customerId);

            if ($customer->anonymised_at !== null) {
                throw ValidationException::withMessages(['name' => 'This customer was anonymised. Their details can no longer be changed.']);
            }

            $values = CustomerFields::clean(Arr::only($data, CustomerFields::EDITABLE));

            if (array_key_exists('name', $values) && $values['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Enter the customer\'s name.']);
            }

            $this->checkCard($customer, $values['card_no'] ?? null);

            $before = $customer->exists ? CustomerFields::snapshot($customer) : null;
            $customer->forceFill($values);
            $after = CustomerFields::snapshot($customer);
            $changed = $before === null ? array_keys($after) : array_keys(array_diff_assoc($after, $before));

            if ($before !== null && $changed === []) {
                return $customer;
            }

            if ($before !== null) {
                $customer->row_version = (int) $customer->row_version + 1;
            }

            $customer->save();

            $this->audit->handle(
                $before === null ? 'customer.created' : 'customer.updated',
                $customer,
                $before === null ? null : Arr::only($before, $changed),
                Arr::only($after, $before === null ? ['name', 'card_no'] : $changed),
                ['name' => $customer->name],
            );

            return $customer;
        }));
    }

    private function checkCard(Customer $customer, ?string $cardNo): void
    {
        if ($cardNo === null || $cardNo === '' || $cardNo === $customer->card_no) {
            return;
        }

        $taken = Customer::query()
            ->when($customer->exists, fn ($query) => $query->whereKeyNot($customer->id))
            ->where('card_no', $cardNo)
            ->value('name');

        if ($taken !== null) {
            throw ValidationException::withMessages(['card_no' => "Card {$cardNo} already belongs to {$taken}."]);
        }
    }
}
