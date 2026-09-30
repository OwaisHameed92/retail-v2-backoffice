<?php

use App\Domain\Reporting\Support\TradingDay;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * DASHBOARD.md §1.3: the trading day is the Europe/London date of completedAt, the hour its local hour; BST/GMT safe.
 */

it('gives the trading day and hour of the §1.3 table', function (string $utc, string $day, int $hour) {
    expect(TradingDay::of($utc))->toBe([$day, $hour]);
})->with([
    'BST morning' => ['2026-09-23T09:41:12Z', '2026-09-23', 10],
    'BST late night is the next day' => ['2026-09-23T23:30:00Z', '2026-09-24', 0],
    'GMT late night' => ['2026-12-01T23:30:00Z', '2026-12-01', 23],
    'first 01:00 hour of 25 Oct (BST)' => ['2026-10-25T00:30:00Z', '2026-10-25', 1],
    'second 01:00 hour of 25 Oct (GMT)' => ['2026-10-25T01:30:00Z', '2026-10-25', 1],
    'last GMT minute before BST starts' => ['2026-03-29T00:59:59Z', '2026-03-29', 0],
    'first BST minute' => ['2026-03-29T01:00:00Z', '2026-03-29', 2],
]);

it('gives 23, 24 and 25 hour windows for the BST days', function () {
    [$spring, $springEnd] = TradingDay::window('2026-03-29');
    [$autumn, $autumnEnd] = TradingDay::window('2026-10-25');
    [$summer, $summerEnd] = TradingDay::window('2026-09-23');

    expect($spring->toIso8601ZuluString())->toBe('2026-03-29T00:00:00Z')
        ->and($spring->diffInHours($springEnd))->toEqual(23)
        ->and($autumn->toIso8601ZuluString())->toBe('2026-10-24T23:00:00Z')
        ->and($autumn->diffInHours($autumnEnd))->toEqual(25)
        ->and($summer->diffInHours($summerEnd))->toEqual(24);
});

it('buckets sales across the October change into the right day and the one hour 1 bucket', function () {
    [$company, $leeds] = TillFixtures::tenant();

    ReportFixtures::push($company, $leeds, [
        ...ReportFixtures::basket('800001', '2026-10-24T22:59:59Z', 10),   // 23:59:59 BST on the 24th
        ...ReportFixtures::basket('800002', '2026-10-24T23:00:00Z', 20),   // 00:00 BST on the 25th
        ...ReportFixtures::basket('800003', '2026-10-25T00:30:00Z', 30),   // 01:30 BST
        ...ReportFixtures::basket('800004', '2026-10-25T01:30:00Z', 40),   // 01:30 GMT
        ...ReportFixtures::basket('800005', '2026-10-25T23:30:00Z', 50),   // 23:30 GMT on the 25th
    ]);

    $hours = DB::table('rpt_sales_hourly')->orderBy('trading_day')->orderBy('hour')->get()
        ->map(fn ($r) => [substr((string) $r->trading_day, 0, 10), (int) $r->hour, (int) $r->txn_count])->all();

    expect($hours)->toBe([
        ['2026-10-24', 23, 1],
        ['2026-10-25', 0, 1],
        ['2026-10-25', 1, 2],
        ['2026-10-25', 23, 1],
    ])
        ->and(DB::table('sales')->where('id', ReportFixtures::saleId('800002'))->value('trading_day'))->toStartWith('2026-10-25');
});
