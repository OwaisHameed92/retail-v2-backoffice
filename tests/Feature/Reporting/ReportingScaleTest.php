<?php

use App\Domain\Reporting\Actions\RebuildReportDays;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.1 scale: the rebuild of a shop-day and the reporting work of a push use a fixed number of queries,
 * whatever the number of sales (grouped queries, bulk inserts, grouped trading-day stamps).
 */

beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
});

function pushSales(object $test, int $count, int $offset): void
{
    $changes = [];

    for ($i = 0; $i < $count; $i++) {
        $n = $offset + $i;
        $changes = [...$changes, ...ReportFixtures::basket(str_pad((string) $n, 6, '0', STR_PAD_LEFT), sprintf('2026-09-23T%02d:%02d:00Z', 8 + intdiv($i, 60) % 10, $i % 60), 10 * $n + 1)];
    }

    ReportFixtures::push($test->company, $test->leeds, $changes);
}

function queriesOf(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('rebuilds a shop-day with the same number of queries for 5 or 80 sales', function () {
    pushSales($this, 5, 100000);
    $small = queriesOf(fn () => app(RebuildReportDays::class)->handle(TillFixtures::COMPANY, TillFixtures::LEEDS, ['2026-09-23']));

    pushSales($this, 75, 200000);
    $large = queriesOf(fn () => app(RebuildReportDays::class)->handle(TillFixtures::COMPANY, TillFixtures::LEEDS, ['2026-09-23']));

    expect($large)->toBe($small)
        ->and(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray(['txn_count' => '80', 'net' => '362.40', 'takings' => '412.00']);
});
