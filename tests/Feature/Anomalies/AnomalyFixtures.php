<?php

namespace Tests\Feature\Anomalies;

use App\Domain\Anomalies\Actions\DetectAnomalies;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures as T;

/**
 * Module 6.6 test rows, written straight to the till and `rpt_*` tables as the sync path stores them (UTC
 * "Y-m-d H:i:s", the London trading day and hour stamped on the sale).
 *
 * "Daily" now is Thursday 8 Oct 2026, 06:30 London: the day examined is Wednesday 7 Oct and the 8-week baseline runs
 * from Wed 12 Aug to Tue 6 Oct. "Hourly" now is Thursday 8 Oct 2026, 15:20 London.
 */
final class AnomalyFixtures
{
    public const DAILY = '2026-10-08 06:30';

    public const HOURLY = '2026-10-08 15:20';

    public const DAY = '2026-10-07';

    public const STAFF_A = '01K5T0Q8C40000000000USR0A1';

    public const STAFF_B = '01K5T0Q8C40000000000USR0B1';

    public const STAFF_C = '01K5T0Q8C40000000000USR0C1';

    /** @var list<array<string, mixed>> */
    private static array $buffer = [];

    /**
     * @return array{found: int, raised: int, notified: int, emailed: int, companies: int}
     */
    public static function detect(string $mode, ?string $at = null): array
    {
        $now = CarbonImmutable::parse($at ?? ($mode === 'daily' ? self::DAILY : self::HOURLY), 'Europe/London');

        return app(DetectAnomalies::class)->handle($mode, $now);
    }

    /** The baseline days before DAY (newest first), 56 of them. */
    public static function baselineDays(int $count = 56): array
    {
        return array_map(fn (int $i) => CarbonImmutable::parse(self::DAY, 'UTC')->subDays($i)->toDateString(), range(1, $count));
    }

    public static function staff(string $companyId): void
    {
        foreach (['A' => self::STAFF_A, 'B' => self::STAFF_B, 'C' => self::STAFF_C] as $name => $id) {
            DB::table('till_users')->insert(['id' => $id, 'company_id' => $companyId, 'name' => 'Staff '.$name, 'is_active' => true]);
        }
    }

    /**
     * Sales of one cashier on a London day and hour (completed sales, or `status` voided / `type` refund).
     *
     * @param  array<string, mixed>  $o
     */
    public static function sales(string $companyId, string $branchId, string $day, int $hour, string $user, int $count, array $o = []): void
    {
        $at = CarbonImmutable::parse(sprintf('%s %02d:%02d', $day, $hour, (int) ($o['minute'] ?? 10)), 'Europe/London')->utc()->format('Y-m-d H:i:s');
        unset($o['minute']);

        for ($i = 0; $i < $count; $i++) {
            self::$buffer[] = [
                'id' => Ulid::new(), 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => T::TILL_1, 'user_id' => $user,
                'type' => 'sale', 'status' => 'completed', 'total' => '10.00', 'completed_at' => $at, 'trading_day' => $day, 'trading_hour' => $hour,
                'original_sale_id' => null, ...$o,
            ];
        }

        if (count(self::$buffer) >= 400) {
            self::flush();
        }
    }

    public static function flush(): void
    {
        foreach (array_chunk(self::$buffer, 200) as $chunk) {
            DB::table('sales')->insert($chunk);
        }

        self::$buffer = [];
    }

    /** No-sale or price-override exception logs. */
    public static function exceptions(string $companyId, string $branchId, string $day, string $user, string $type, int $count): void
    {
        $at = CarbonImmutable::parse($day.' 12:00', 'Europe/London')->utc()->format('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            DB::table('exception_logs')->insert(['id' => Ulid::new(), 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => T::TILL_1, 'user_id' => $user, 'type' => $type, 'at' => $at]);
        }
    }

    public static function daily(string $companyId, string $branchId, string $day, string $gross, int $txn): void
    {
        DB::table('rpt_sales_daily')->insert([
            'company_id' => $companyId, 'branch_id' => $branchId, 'trading_day' => $day, 'register_id' => '',
            'gross' => $gross, 'net' => $gross, 'txn_count' => $txn, 'takings' => $gross, 'rebuilt_at' => '2026-10-08 05:00:00',
        ]);
    }

    public static function hourly(string $companyId, string $branchId, string $day, int $hour, int $txn): void
    {
        DB::table('rpt_sales_hourly')->insert([
            'company_id' => $companyId, 'branch_id' => $branchId, 'trading_day' => $day, 'register_id' => '', 'hour' => $hour,
            'gross' => $txn * 10, 'net' => $txn * 10, 'txn_count' => $txn, 'rebuilt_at' => '2026-10-08 05:00:00',
        ]);
    }

    /** A closed shift with a cash difference (negative = short), closed on a London day at 22:00. */
    public static function shift(string $companyId, string $branchId, string $day, string $user, string $variance, string $register = T::TILL_1): void
    {
        DB::table('payment_types')->insertOrIgnore(['id' => substr($companyId, 0, 22).'CASH', 'company_id' => $companyId, 'name' => 'Cash', 'is_cash' => true, 'is_card' => false]);
        $id = Ulid::new();
        $closed = CarbonImmutable::parse($day.' 21:00', 'Europe/London')->utc()->format('Y-m-d H:i:s');
        DB::table('shifts')->insert(['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => $register, 'user_id' => $user,
            'opened_at' => $closed, 'closed_at' => $closed, 'status' => 'closed', 'variance_total' => $variance, 'opening_float' => '100.00']);
        DB::table('shift_tenders')->insert(['id' => Ulid::new(), 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => $register, 'shift_id' => $id,
            'payment_type_id' => substr($companyId, 0, 22).'CASH', 'expected' => '500.00', 'declared' => bcadd('500.00', $variance, 2), 'variance' => $variance]);
    }

    /** A product whose stock went from 1 to −1 on a London day. */
    public static function goneNegative(string $companyId, string $branchId, string $day, string $productId): void
    {
        DB::table('stock_movements')->insert(['id' => Ulid::new(), 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'type' => 'sale',
            'qty_delta' => '-2', 'qty_before' => '1', 'qty_after' => '-1', 'at' => CarbonImmutable::parse($day.' 13:00', 'Europe/London')->utc()->format('Y-m-d H:i:s')]);
    }

    /** The same hours every day of the week. */
    public static function hours(string $companyId, string $branchId, string $opens, string $closes): void
    {
        foreach (range(1, 7) as $weekday) {
            DB::table('shop_opening_hours')->insert(['id' => Ulid::new(), 'company_id' => $companyId, 'branch_id' => $branchId, 'weekday' => $weekday,
                'is_closed' => false, 'opens_at' => $opens, 'closes_at' => $closes, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * A stored finding, for page, permission, digest and tool tests.
     *
     * @param  array<string, mixed>  $o
     */
    public static function anomaly(string $companyId, string $branchId, AnomalyKind $kind, AnomalySeverity $severity = AnomalySeverity::Medium, array $o = []): Anomaly
    {
        $subject = $kind->staffLevel() ? self::STAFF_A : null;

        return Anomaly::withoutCompanyScope()->create([
            'company_id' => $companyId, 'branch_id' => $branchId, 'kind' => $kind, 'severity' => $severity, 'staff_level' => $kind->staffLevel(),
            'subject_id' => $subject, 'subject_name' => $subject !== null ? 'Staff A' : null,
            'dedupe_key' => $kind->value.'|'.$branchId.'|'.($subject ?? '-').'|'.self::DAY.'|'.Ulid::new(), 'group_key' => $kind->value.'|'.$branchId.'|'.($subject ?? '-'),
            'trading_day' => self::DAY, 'period_start' => '2026-10-06 23:00:00', 'period_end' => '2026-10-07 23:00:00',
            'title' => $kind->label().' at '.$branchId, 'summary' => 'Summary of '.$kind->value.'.',
            'facts' => [['label' => 'Voids on Wed 7 Oct', 'value' => '9'], ['label' => 'Voids per 100 sales', 'value' => '30.0', 'usual' => '3.3', 'peers' => '6.7']],
            'links' => [['label' => 'Their voided sales that day', 'href' => '/app/sales?status=voided'], ['label' => 'Staff member', 'href' => '/app/staff/'.self::STAFF_A.'/edit']],
            'score' => '12.00', 'status' => AnomalyStatus::New, 'detected_at' => now(), ...$o,
        ]);
    }
}
