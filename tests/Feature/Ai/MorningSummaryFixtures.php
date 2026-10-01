<?php

namespace Tests\Feature\Ai;

use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/**
 * Module 6.3 fixtures. "Now" is Thursday 8 Oct 2026, 07:00 London; yesterday Wednesday 7 Oct; the same weekday last
 * week 30 Sept; last year Wednesday 8 Oct 2025 (364 days before yesterday).
 *
 * Kirkgate yesterday: Leeds £1,200.00 (100 sales, £60.00 refunded in 3, usual £10.00 a day), Bradford nothing.
 * Last week: Leeds £1,000.00, Bradford £500.00. Last year: Leeds £960.00. Products at Leeds: Cola £84 (last week
 * £60, 30 sold this week, 2 on hand), Bread £12.50 (£30), Milk £20 (£18).
 */
final class MorningSummaryFixtures
{
    public const NOW = '2026-10-08 07:00';

    public static function plan(Company $company, bool $withAi = true): void
    {
        $plan = Plan::factory()->features($withAi ? [Feature::Assist] : [])->create(['code' => 'ms-'.str()->lower(str()->random(6))]);
        $company->forceFill(['plan_id' => $plan->id])->save();
    }

    /** @param array<string, mixed> $o */
    public static function sales(string $companyId, string $branchId, string $day, string $gross, int $txn, array $o = []): void
    {
        DB::table('rpt_sales_daily')->insert([
            'company_id' => $companyId, 'branch_id' => $branchId, 'trading_day' => $day, 'register_id' => '',
            'gross' => $gross, 'net' => $gross, 'txn_count' => $txn, 'takings' => $gross, 'rebuilt_at' => '2026-10-08 05:00:00', ...$o,
        ]);
    }

    public static function product(string $companyId, string $branchId, string $day, string $productId, string $gross, string $qty): void
    {
        DB::table('rpt_product_daily')->insert([
            'company_id' => $companyId, 'branch_id' => $branchId, 'trading_day' => $day, 'register_id' => '', 'product_id' => $productId,
            'qty' => $qty, 'gross' => $gross, 'net' => $gross, 'last_name' => 'Line '.$productId, 'rebuilt_at' => '2026-10-08 05:00:00',
        ]);
    }

    public static function kirkgate(Company $company): void
    {
        $id = $company->id;
        self::sales($id, T::LEEDS, '2026-10-07', '1200.00', 100, ['refund_gross' => '60.00', 'refund_count' => 3]);
        self::sales($id, T::LEEDS, '2026-09-30', '1000.00', 90, ['refund_gross' => '10.00', 'refund_count' => 1]);
        self::sales($id, T::BRADFORD, '2026-09-30', '500.00', 40);
        self::sales($id, T::LEEDS, '2025-10-08', '960.00', 80);

        foreach (range(10, 19) as $d) {
            self::sales($id, T::LEEDS, '2026-09-'.$d, '900.00', 80, ['refund_gross' => '10.00', 'refund_count' => 1]);
        }

        S::product($id, S::id('MS', 1), 'Cola 500ml');
        S::product($id, S::id('MS', 2), 'Bread');
        S::product($id, S::id('MS', 3), 'Milk');
        S::line($id, T::LEEDS, S::id('MS', 1), '2');
        S::line($id, T::LEEDS, S::id('MS', 2), '3');
        self::product($id, T::LEEDS, '2026-10-07', S::id('MS', 1), '84.00', '30');
        self::product($id, T::LEEDS, '2026-09-30', S::id('MS', 1), '60.00', '20');
        self::product($id, T::LEEDS, '2026-10-07', S::id('MS', 2), '12.50', '5');
        self::product($id, T::LEEDS, '2026-09-30', S::id('MS', 2), '30.00', '12');
        self::product($id, T::LEEDS, '2026-10-07', S::id('MS', 3), '20.00', '16');
        self::product($id, T::LEEDS, '2026-09-30', S::id('MS', 3), '18.00', '15');
    }

    /** A narrative using only figures of the Kirkgate facts. */
    public static function goodNarrative(): string
    {
        return 'Leeds took £1,200.00 yesterday, -20.0% on the same weekday last week across your shops and +25.0% on last year. '
            .'Bradford had no sales reach the portal, against £500.00 last week. '
            .'Leeds refunded £60.00, against a usual £10.00 a day. Cola 500ml is running low.';
    }

    public static function mailTo(string $email): OwnerDigestMail
    {
        $found = Mail::queued(OwnerDigestMail::class, fn (OwnerDigestMail $mail) => $mail->hasTo($email));
        expect($found)->toHaveCount(1);

        return $found->first();
    }
}
