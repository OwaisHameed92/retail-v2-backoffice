<?php

namespace Tests\Feature\Reporting;

use App\Domain\Reporting\Actions\CheckReports;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Sync\Data\ApplyResult;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/**
 * Baskets for the reporting tests, cloned from `samples/push-request.json` (Warburtons £1.45 zero-rated + 2 × Coca-Cola
 * £3.70 standard-rated, card £5.15): the six sale rows with new ids, times, type, status, till and cashier.
 */
final class ReportFixtures
{
    public const USER_1 = '01K5T0Q8C4000000000000A001';

    public const USER_2 = '01K5T0Q8C4000000000000A002';

    /**
     * @param  array{type?: string, status?: string, register?: string, branch?: string, user?: string, version?: int, colaOnly?: bool, original?: string}  $o
     * @return list<array<string, mixed>>
     */
    public static function basket(string $number, string $completedAt, int $seq, array $o = []): array
    {
        $json = (string) json_encode(array_slice(TillFixtures::sample('push-request.json'), 0, 6));
        $rows = json_decode(str_replace(['000482', '2026-09-23T09:41:12Z'], [$number, $completedAt], $json), true);
        $type = $o['type'] ?? 'sale';
        $status = $o['status'] ?? 'completed';
        $refund = $type === 'refund';
        $version = $o['version'] ?? 1;
        $out = [];

        foreach ($rows as $row) {
            $p = &$row['payload'];

            if (($refund || ($o['colaOnly'] ?? false)) && in_array($row['entityId'], ["01K5VB0000000SN1R001{$number}", "01K5VB0000000SVZR001{$number}"], true)) {
                continue;
            }

            if ($row['entity'] === 'Sale') {
                $p['type'] = $type;
                $p['status'] = $status;
                $p['completedAt'] = $status === 'completed' ? $completedAt : null;
                $p['isCompleted'] = $status === 'completed';
                $p['userId'] = $o['user'] ?? self::USER_1;
                $p['originalSaleId'] = $o['original'] ?? null;
                $p['registerId'] = $row['registerId'] = $o['register'] ?? TillFixtures::TILL_1;
                $p['branchId'] = $row['branchId'] = $o['branch'] ?? TillFixtures::LEEDS;

                if ($refund || ($o['colaOnly'] ?? false)) {
                    [$p['subtotal'], $p['total'], $p['vatTotal']] = [3.7, 3.7, 0.62];
                }
            }

            if ($row['entity'] === 'SalePayment' && ($refund || ($o['colaOnly'] ?? false))) {
                $p['amount'] = $p['appliedAmount'] = 3.7;
            }

            if ($refund) {
                foreach (['subtotal', 'total', 'vatTotal', 'qty', 'baseQty', 'goodsTotal', 'lineTotal', 'vatAmount', 'costAtSale', 'amount', 'appliedAmount', 'net', 'vat', 'gross'] as $field) {
                    if (isset($p[$field]) && is_numeric($p[$field])) {
                        $p[$field] = -$p[$field];
                    }
                }
            }

            $p['rowVersion'] = $version;
            unset($p);
            $row['version'] = $version;
            $row['seq'] = $seq++;
            $row['key'] = "{$row['entity']}:{$row['entityId']}:{$version}";
            $out[] = $row;
        }

        return $out;
    }

    public static function saleId(string $number): string
    {
        return "01K5VB000000000SR001{$number}";
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    public static function push(Company $company, Branch $sender, array $changes): ApplyResult
    {
        return TillFixtures::apply($company, $sender, $changes);
    }

    /**
     * Every reporting row of a company, canonical and without `rebuilt_at`.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function snapshot(?string $companyId = null): array
    {
        $out = [];

        foreach (ReportTables::names() as $table) {
            $rows = DB::table($table)->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get()
                ->map(fn ($r) => CheckReports::canonical($table, (array) $r))->all();
            usort($rows, fn ($a, $b) => ReportTables::rowKey($table, $a) <=> ReportTables::rowKey($table, $b));
            $out[$table] = $rows;
        }

        return $out;
    }

    /**
     * One rpt_sales_daily row as canonical strings.
     *
     * @return array<string, string>|null
     */
    public static function daily(string $branch, string $register, string $day): ?array
    {
        $row = DB::table(ReportTables::SALES_DAILY)->where('branch_id', $branch)->where('register_id', $register)->where('trading_day', $day)->first();

        return $row === null ? null : CheckReports::canonical(ReportTables::SALES_DAILY, (array) $row);
    }
}
