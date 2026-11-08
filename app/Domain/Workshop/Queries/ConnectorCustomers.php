<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\TillData\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * BikeBook's `Customer` (WORKSHOP-BIKEBOOK.md §3.5), read inside the connection's company. Only name, phone, e-mail,
 * one address, dates and version: never `balance`, `points`, `creditLimit`, `cardNo`, `dob` or `notes`.
 *
 * List: active, not anonymised customers; with `lastUpdated`, those changed at or after it, plus customers anonymised
 * since then, once, with empty personal fields (GDPR).
 */
final class ConnectorCustomers
{
    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(?CarbonImmutable $since, int $page, int $size, bool $light): array
    {
        $at = $since?->utc()->format('Y-m-d H:i:s');
        $query = Customer::query()
            ->when($at === null, fn (Builder $q) => $q->where('is_active', true)->whereNull('anonymised_at'))
            ->when($at !== null, fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->where('updated_at', '>=', $at)->orWhere('synced_at', '>=', $at))
                ->where(fn (Builder $w) => $w->where(fn (Builder $live) => $live->where('is_active', true)->whereNull('anonymised_at'))
                    ->orWhere('anonymised_at', '>=', $at)));
        $total = (clone $query)->count();
        $customers = $query->orderBy('id')->forPage($page, $size)->get();

        return [$customers->map(fn (Customer $c) => $light ? ['id' => $c->id, 'updated' => ApiDate::format($c->updated_at)] : self::model($c))->values()->all(), $total];
    }

    /**
     * @return array<string, mixed>
     */
    public static function model(Customer $customer): array
    {
        if ($customer->anonymised_at !== null) {
            return [
                'id' => $customer->id, 'firstName' => '', 'lastName' => '', 'emailAddress' => '', 'phone' => '',
                'createDate' => ApiDate::format($customer->created_at), 'updateDate' => ApiDate::format($customer->updated_at),
                'version' => (string) $customer->row_version, 'addresses' => [], 'anonymised' => true,
            ];
        }

        [$first, $last] = self::splitName($customer->name);

        return [
            'id' => $customer->id,
            'firstName' => $first,
            'lastName' => $last,
            'emailAddress' => $customer->email,
            'phone' => $customer->phone,
            'createDate' => ApiDate::format($customer->created_at),
            'updateDate' => ApiDate::format($customer->updated_at),
            'version' => (string) $customer->row_version,
            'addresses' => trim($customer->address) === '' ? [] : [self::address($customer->address)],
            'anonymised' => false,
        ];
    }

    /**
     * "Ali Khan" → ["Ali", "Khan"]; split at the last space (first = everything before it).
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $at = strrpos($name, ' ');

        return $at === false ? [$name, ''] : [substr($name, 0, $at), substr($name, $at + 1)];
    }

    /**
     * One address parsed from the stored text ("line1, line2, town, postcode"): the postcode is the last UK postcode
     * found; the parts before it are line1, line2 and town.
     *
     * @return array{line1: string, line2: string, town: string, postcode: string}
     */
    public static function address(string $text): array
    {
        $postcode = '';

        if (preg_match_all('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i', $text, $matches, PREG_SET_ORDER) > 0) {
            $last = end($matches);
            $postcode = strtoupper($last[1].' '.$last[2]);
            $text = str_ireplace($last[0], '', $text);
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $text) ?: []), fn ($p) => $p !== ''));
        $town = count($parts) > 1 ? (string) array_pop($parts) : '';

        return ['line1' => (string) ($parts[0] ?? ''), 'line2' => implode(', ', array_slice($parts, 1)), 'town' => $town, 'postcode' => $postcode];
    }
}
