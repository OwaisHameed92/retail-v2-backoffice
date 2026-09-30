<?php

namespace App\Domain\Reporting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Stamps `sales.trading_day` / `trading_hour` (DASHBOARD.md §1.3, "store them on your sale row at ingest"):
 *
 * - completed: the local date and hour of `completed_at` (what every figure uses);
 * - voided (an abandoned basket, never completed): of the moment it was voided, the row's `updated_at` (else
 *   `created_at`), so the void count lands on a day;
 * - anything else (open, held, a completed row without `completed_at`): null — it counts nowhere.
 *
 * Rows whose stored values already match are not written, so a replay changes nothing. Updates are grouped by
 * (day, hour): a push of a day's sales is a handful of UPDATE … WHERE id IN (…) statements.
 */
final class SaleDayStamper
{
    private const CHUNK = 500;

    /**
     * @param  list<string>  $ids  sale ids of one company
     * @return array<string, array{branch: string|null, day: string|null}> id => the sale's shop and trading day now
     */
    public function stamp(string $companyId, array $ids): array
    {
        $result = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $rows = DB::table('sales')->where('company_id', $companyId)->whereIn('id', $chunk)
                ->get(['id', 'branch_id', 'status', 'completed_at', 'updated_at', 'created_at', 'trading_day', 'trading_hour']);
            $result += $this->write($rows->all());
        }

        return $result;
    }

    /**
     * Stamps every sale of a company that has none yet (rows stored before module 3.1). Returns how many changed.
     */
    public function stampMissing(string $companyId): int
    {
        $count = 0;

        DB::table('sales')->where('company_id', $companyId)->whereNull('trading_day')
            ->where(fn ($q) => $q->where(fn ($c) => $c->where('status', 'completed')->whereNotNull('completed_at'))->orWhere('status', 'voided'))
            ->select(['id', 'branch_id', 'status', 'completed_at', 'updated_at', 'created_at', 'trading_day', 'trading_hour'])
            ->chunkById(self::CHUNK, function ($rows) use (&$count) {
                $count += count(array_filter($this->write($rows->all()), fn (array $r) => $r['day'] !== null));
            });

        return $count;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, array{branch: string|null, day: string|null}>
     */
    private function write(array $rows): array
    {
        $result = [];
        $groups = [];

        foreach ($rows as $row) {
            [$day, $hour] = self::dayOf($row);
            $result[(string) $row->id] = ['branch' => $row->branch_id === null ? null : (string) $row->branch_id, 'day' => $day];

            $storedDay = $row->trading_day === null ? null : substr((string) $row->trading_day, 0, 10);
            $storedHour = $row->trading_hour === null ? null : (int) $row->trading_hour;

            if ($storedDay !== $day || $storedHour !== $hour) {
                $groups[($day ?? '').'|'.($hour ?? '')][] = (string) $row->id;
            }
        }

        foreach ($groups as $key => $ids) {
            [$day, $hour] = explode('|', $key);
            DB::table('sales')->whereIn('id', $ids)->update([
                'trading_day' => $day === '' ? null : $day,
                'trading_hour' => $hour === '' ? null : (int) $hour,
            ]);
        }

        return $result;
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    public static function dayOf(object $sale): array
    {
        $at = match ($sale->status) {
            'completed' => $sale->completed_at,
            'voided' => $sale->updated_at ?? $sale->created_at,
            default => null,
        };

        return $at === null ? [null, null] : TradingDay::of((string) $at);
    }
}
