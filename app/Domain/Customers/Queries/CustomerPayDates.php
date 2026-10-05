<?php

namespace App\Domain\Customers\Queries;

use App\Domain\TillData\Enums\AccountPayDateReminderChannel;
use App\Domain\TillData\Models\AccountPayDate;

/**
 * A customer's current pay dates and reminder state (module 4.4, till 0.1.51 `AccountPayDate`, PORTAL-CHANGES-2026-10-06
 * §2.3): the rows no newer pay date replaced (`replacedAt` null, the till's `isCurrent`), soonest first. Read only;
 * whether anything is still owed is the ledger's (the page's balance), never this table's. Runs in the company scope.
 */
final class CustomerPayDates
{
    private const LIMIT = 10;

    /**
     * @param  array<string, string>  $branches  branch id => name
     * @return list<array<string, mixed>>
     */
    public static function current(string $customerId, array $branches): array
    {
        return AccountPayDate::query()->where('customer_id', $customerId)->whereNull('replaced_at')
            ->orderBy('due_at')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (AccountPayDate $p) => [
                'id' => $p->id,
                'dueAt' => $p->due_at->toIso8601ZuluString(),
                'wholeAccount' => (string) $p->sale_id === '',
                'saleId' => (string) $p->sale_id !== '' ? $p->sale_id : null,
                'note' => (string) $p->note !== '' ? $p->note : null,
                'shop' => $branches[(string) $p->branch_id] ?? 'Another shop',
                'reminder' => self::reminder($p),
            ])->values()->all();
    }

    /**
     * The last reminder: sent (when, how), failed (why: the first line of the till's multi-line error, then the
     * rest), or none yet.
     *
     * @return array{state: string, at: string|null, channel: string|null, attempts: int, error: string|null, detail: string|null}
     */
    private static function reminder(AccountPayDate $p): array
    {
        $error = trim(str_replace("\r\n", "\n", (string) $p->last_reminder_error));
        $lines = $error === '' ? [] : explode("\n", $error);
        $failed = $error !== '';

        return [
            'state' => $failed ? 'failed' : ($p->reminder_sent_at !== null ? 'sent' : 'none'),
            'at' => ($failed ? $p->last_reminder_at : $p->reminder_sent_at)?->toIso8601ZuluString(),
            'channel' => match ($p->reminder_channel) {
                AccountPayDateReminderChannel::WhatsApp => 'WhatsApp',
                AccountPayDateReminderChannel::Email => 'Email',
                default => null,
            },
            'attempts' => (int) $p->reminder_attempts,
            'error' => $lines[0] ?? null,
            'detail' => count($lines) > 1 ? trim(implode("\n", array_slice($lines, 1))) : null,
        ];
    }
}
