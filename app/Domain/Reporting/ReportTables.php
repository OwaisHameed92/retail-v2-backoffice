<?php

namespace App\Domain\Reporting;

/**
 * The `rpt_*` tables (contract v1.4.1 DASHBOARD.md §4.2–4.6) in one place: key columns (after company, shop and
 * trading day), decimal columns with their scale, counts and labels. The builder, the writer and the check all
 * read this, so a column added here is rebuilt, written and compared everywhere.
 */
final class ReportTables
{
    public const SALES_DAILY = 'rpt_sales_daily';

    public const SALES_HOURLY = 'rpt_sales_hourly';

    public const TENDER_DAILY = 'rpt_tender_daily';

    public const PRODUCT_DAILY = 'rpt_product_daily';

    public const VAT_DAILY = 'rpt_vat_daily';

    public const STAFF_DAILY = 'rpt_staff_daily';

    public const DIRTY_DAYS = 'rpt_dirty_days';

    /** Every row starts with these (the rebuild unit is one shop's trading days). */
    public const SCOPE = ['company_id', 'branch_id', 'trading_day'];

    /**
     * @var array<string, array{keys: list<string>, decimals: array<string, int>, counts: list<string>, labels: list<string>}>
     */
    public const TABLES = [
        self::SALES_DAILY => [
            'keys' => ['register_id'],
            'decimals' => [
                'gross' => 2, 'net' => 2, 'vat' => 2, 'refund_gross' => 2, 'refund_net' => 2, 'discount' => 2,
                'promo' => 2, 'coupon' => 2, 'cost' => 4, 'container_deposits' => 2, 'takings' => 2, 'void_total' => 2,
                'staff_discount' => 2, 'order_deposits' => 2, 'charity' => 2,
            ],
            'counts' => ['txn_count', 'refund_count', 'void_count'],
            'labels' => [],
        ],
        self::SALES_HOURLY => [
            'keys' => ['register_id', 'hour'],
            'decimals' => ['net' => 2, 'gross' => 2],
            'counts' => ['txn_count'],
            'labels' => [],
        ],
        self::TENDER_DAILY => [
            'keys' => ['register_id', 'payment_type_id'],
            'decimals' => ['amount' => 2, 'refunds' => 2],
            'counts' => ['count'],
            'labels' => ['payment_type_name'],
        ],
        self::PRODUCT_DAILY => [
            'keys' => ['register_id', 'product_id'],
            'decimals' => [
                'qty' => 4, 'refund_qty' => 4, 'gross' => 2, 'net' => 2, 'vat' => 2, 'refund_net' => 2,
                'discount' => 2, 'promo' => 2, 'cost' => 4,
            ],
            'counts' => [],
            'labels' => ['last_name'],
        ],
        self::VAT_DAILY => [
            'keys' => ['register_id', 'vat_rate_id', 'percentage'],
            'decimals' => ['net' => 2, 'vat' => 2, 'gross' => 2],
            'counts' => [],
            'labels' => ['code'],
        ],
        self::STAFF_DAILY => [
            'keys' => ['register_id', 'user_id'],
            'decimals' => ['gross' => 2, 'net' => 2, 'refund_gross' => 2],
            'counts' => ['txn_count', 'refund_count', 'void_count'],
            'labels' => [],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Every column of a table's rows, in insert order.
     *
     * @return list<string>
     */
    public static function columns(string $table): array
    {
        $t = self::TABLES[$table];

        return [...self::SCOPE, ...$t['keys'], ...array_keys($t['decimals']), ...$t['counts'], ...$t['labels'], 'rebuilt_at'];
    }

    /**
     * The identity of a row inside its table: scope + keys joined.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowKey(string $table, array $row): string
    {
        $parts = [];

        foreach ([...self::SCOPE, ...self::TABLES[$table]['keys']] as $column) {
            $parts[] = (string) $row[$column];
        }

        return implode('|', $parts);
    }
}
