<?php

namespace App\Domain\Privacy\Support;

use App\Domain\TillData\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Erases the portal's own copies of an anonymised customer's details (module 7.7): the before/after values of the
 * activity log entries about them (the action, actor and time stay) and the address of statement emails sent to
 * them. The activity log is otherwise append-only; erasure is the one exception, so it is written with the query
 * builder (AuditLog refuses model updates).
 */
final class ErasureTraces
{
    public const ERASED = '[erased]';

    public static function erase(Customer $customer, string $email): void
    {
        $rows = DB::table('audit_logs')
            ->where('company_id', $customer->company_id)
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', $customer->id)
            ->get(['id', 'before', 'after', 'meta']);

        foreach ($rows as $row) {
            DB::table('audit_logs')->where('id', $row->id)->update([
                'before' => self::blank($row->before),
                'after' => self::blank($row->after),
                'meta' => self::blank($row->meta, ['name']),
            ]);
        }

        if (trim($email) !== '') {
            DB::table('email_logs')->where('company_id', $customer->company_id)->whereRaw('LOWER(`to`) = ?', [mb_strtolower(trim($email))])
                ->update(['to' => self::ERASED, 'meta' => null]);
        }
    }

    /**
     * Every value (or only `$keys`) of a JSON object replaced by "[erased]".
     *
     * @param  list<string>|null  $keys
     */
    private static function blank(mixed $json, ?array $keys = null): ?string
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($values)) {
            return is_string($json) ? $json : null;
        }

        foreach ($values as $key => $value) {
            if ($keys === null || in_array($key, $keys, true)) {
                $values[$key] = self::ERASED;
            }
        }

        return (string) json_encode($values);
    }
}
