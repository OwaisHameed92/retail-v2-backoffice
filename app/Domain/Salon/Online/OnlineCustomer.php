<?php

namespace App\Domain\Salon\Online;

use App\Domain\Customers\Support\CustomerFields;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Customer;

/**
 * The `Customer` an online booking request names (W3a, salon pack README §5 `customerId`, §11): needed for the
 * client's marketing choices to become Consent rows on the till.
 *
 * An existing, live customer of the business is used only when both the e-mail (any case) and the phone (digits only)
 * match: a stranger who knows someone's e-mail alone cannot attach a request to them. Otherwise a new customer is
 * made (hub-owned, through the model, so it reaches every till before the request that names it: Customer comes
 * before SalonOnlineBooking in the pull's apply order and is saved first). The audit entry records only that the
 * online booking page made it, never the client's details. Runs in the shop's company, inside the request's
 * transaction.
 */
final class OnlineCustomer
{
    public static function matchOrCreate(string $firstName, string $lastName, string $phone, string $email): string
    {
        $digits = self::digits($phone);
        $email = mb_strtolower(trim($email));

        if ($digits !== '' && $email !== '') {
            $match = Customer::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('anonymised_at')
                ->where('is_active', true)
                ->orderBy('created_at')
                ->get(['id', 'phone'])
                ->first(fn (Customer $c) => self::digits((string) $c->phone) === $digits);

            if ($match !== null) {
                return $match->id;
            }
        }

        $customer = (new Customer)->forceFill([
            ...CustomerFields::defaults(),
            'name' => trim($firstName.' '.$lastName),
            'phone' => trim($phone),
            'email' => trim($email),
        ]);
        $customer->save();

        app(RecordAudit::class)->handle('customer.created', $customer, null, ['source' => 'onlineBooking']);

        return $customer->id;
    }

    private static function digits(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        // 07700 900123 and +44 7700 900123 are the same phone.
        return str_starts_with($digits, '44') && strlen($digits) >= 12 ? '0'.substr($digits, 2) : $digits;
    }
}
