<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Customer;
use App\Domain\Workshop\Support\ConnectorException;
use App\Domain\Workshop\Support\UkPhone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BikeBook creates or changes a customer (WORKSHOP-BIKEBOOK.md §3.5). Customers are portal-owned master data: the
 * write goes through SaveCustomer (module 4.4), so every till of the business gets it in its next pull like any
 * customer made on the portal, with the usual audit entry.
 *
 * - create: de-duplicated first — the same phone (UK digits, UkPhone) or the same e-mail (any case) returns the
 *   existing customer and changes nothing (`created` false). So a repeat of the same POST never makes a second row.
 * - update: name, phone and e-mail only (absent members unchanged).
 * - address: the one address of our model, stored as the till shows it ("line1, line2, town, postcode").
 *
 * Only name, phone, e-mail and address are ever written; never balance, points, credit limit, card or notes.
 */
final class SaveWorkshopCustomer
{
    public function __construct(private readonly SaveCustomer $save) {}

    /**
     * @param  array{name: string, phone: string, email: string, address?: string}  $details
     * @return array{0: Customer, 1: bool} the customer, and whether it was created
     */
    public function create(Company $company, array $details): array
    {
        // Two identical POSTs at once (BikeBook retrying) must still make one customer.
        $key = 'workshop:customer:'.$company->id.':'.hash('sha256', UkPhone::normalise($details['phone']).'|'.mb_strtolower(trim($details['email'])));

        return Cache::lock($key, 10)->block(5, function () use ($company, $details): array {
            $existing = $this->duplicate($details['phone'], $details['email']);

            return $existing !== null ? [$existing, false] : [$this->write($company, null, $details), true];
        });
    }

    /**
     * @param  array<string, string>  $details  any of name, phone, email, address
     */
    public function update(Company $company, string $customerId, array $details): Customer
    {
        $customer = Customer::query()->whereKey($customerId)->first();

        if ($customer === null) {
            throw ConnectorException::notFound('No customer with this id in this shop\'s business.');
        }

        if ($customer->anonymised_at !== null) {
            throw ConnectorException::invalid('This customer was anonymised. Their details can no longer be changed.');
        }

        return $details === [] ? $customer : $this->write($company, $customer->id, $details);
    }

    /** The existing active customer with this phone or e-mail, if any. Call inside the company. */
    public function duplicate(string $phone, string $email): ?Customer
    {
        $email = mb_strtolower(trim($email));

        if ($email !== '' && ($byEmail = Customer::query()->whereNull('anonymised_at')->where('email', $email)->orderBy('created_at')->first()) !== null) {
            return $byEmail;
        }

        $digits = UkPhone::normalise($phone);

        if (! UkPhone::comparable($digits)) {
            return null;
        }

        // Narrow by the last 9 digits in SQL (spaces, dashes, brackets and + removed), then compare exactly.
        $stripped = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
        $candidates = Customer::query()->whereNull('anonymised_at')->where('phone', '<>', '')
            ->whereRaw("{$stripped} LIKE ?", ['%'.substr($digits, -9)])->orderBy('created_at')->limit(50)->get();

        return $candidates->first(fn (Customer $c) => UkPhone::normalise($c->phone) === $digits);
    }

    /**
     * @param  array<string, string>  $details
     */
    private function write(Company $company, ?string $customerId, array $details): Customer
    {
        try {
            return DB::transaction(fn () => $this->save->handle($company, $customerId, $details));
        } catch (ValidationException $e) {
            throw ConnectorException::invalid(collect($e->errors())->flatten()->first() ?? 'The customer is not valid.');
        }
    }
}
