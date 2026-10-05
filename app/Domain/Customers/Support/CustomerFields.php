<?php

namespace App\Domain\Customers\Support;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonInterface;

/**
 * The customer columns the portal may change (module 4.4) and how they are stored. Text members are non-null strings
 * in the till's schema, so an empty field is stored as "" (never null); `dob` is a date or null; money is a
 * normalised 2 dp string. `balance` / `points` are not here: they are the ledger's (contract §10.1).
 */
final class CustomerFields
{
    /** @var list<string> */
    public const EDITABLE = ['name', 'phone', 'email', 'address', 'dob', 'card_no', 'credit_limit', 'tier', 'notes', 'is_active'];

    /** @var list<string> */
    private const TEXT = ['name', 'phone', 'email', 'address', 'card_no', 'tier', 'notes'];

    /**
     * Values of a new customer before the input is applied.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => '', 'phone' => '', 'email' => '', 'address' => '', 'dob' => null, 'card_no' => '', 'balance' => '0.00',
            'points' => 0, 'credit_limit' => '0.00', 'tier' => '', 'notes' => '', 'is_active' => true, 'anonymised_at' => null,
            // Till 0.1.28 / 0.1.32: a customer the portal adds collects points and holds none back yet.
            'pending_points' => 0, 'earns_points' => true,
        ];
    }

    /**
     * Trims text, keeps "" for empty text, normalises money and dates.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                in_array($key, self::TEXT, true) => trim((string) $value),
                $key === 'dob' => $value === null || $value === '' ? null : substr((string) $value, 0, 10),
                $key === 'credit_limit' => Money::normalise($value === null || $value === '' ? '0' : $value),
                $key === 'is_active' => (bool) $value,
                default => $value,
            };
        }

        if (isset($values['email'])) {
            $values['email'] = mb_strtolower($values['email']);
        }

        if (isset($values['card_no'])) {
            $values['card_no'] = strtoupper($values['card_no']);
        }

        return $values;
    }

    /**
     * The editable members in a comparable form (strings), for "did anything change" and the audit trail.
     *
     * @return array<string, string>
     */
    public static function snapshot(Customer $customer): array
    {
        $values = [];

        foreach (self::EDITABLE as $column) {
            $value = $customer->getAttribute($column);
            $values[$column] = match (true) {
                $value instanceof CarbonInterface => $value->format('Y-m-d'),
                is_bool($value) => $value ? '1' : '0',
                $column === 'credit_limit' => Money::normalise($value ?? '0'),
                default => (string) $value,
            };
        }

        return $values;
    }
}
