<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 2.2: one full push (5,000 rows, gzip) through HTTP — auth, gunzip, decode, id translation, the branch
 * lock, ApplySyncChanges and the status row — must finish well inside the till's 30 s timeout (contract §3).
 * Budget: SYNC_PUSH_PERF_BUDGET_MS (default 10,000 ms) of CPU time; wall time is printed next to it.
 */
it('stores a 5,000-row gzip push well inside the till\'s 30-second timeout', function () {
    $sync = new SyncApiFixtures($this);
    $templates = collect(TillFixtures::sample('push-request.json'))->keyBy(fn ($c) => $c['entity'].$c['seq']);
    $product = TillFixtures::sample('entities/Product.json');
    $id = fn (string $kind, int $n) => '01K6'.$kind.str_pad((string) $n, 21, '0', STR_PAD_LEFT);
    $changes = [];
    $seq = 1;

    for ($p = 1; $p <= 100; $p++) {
        $changes[] = TillFixtures::envelope('Product', [...$product, 'id' => $id('P', $p), 'name' => "Product {$p}", 'sellPrice' => 1 + $p / 100], $seq++);
    }

    for ($s = 1; $s <= 700; $s++) {
        $saleId = $id('S', $s);

        foreach (['Sale18231', 'SaleLine18232', 'SaleLine18233', 'SalePayment18234', 'SaleVat18235', 'StockMovement18237', 'StockMovement18238'] as $n => $key) {
            $template = $templates[$key];
            $payload = [...$template['payload'], 'id' => $n === 0 ? $saleId : $id(chr(65 + $n), $s * 10 + $n)];
            $payload[$n === 0 ? 'number' : ($template['entity'] === 'StockMovement' ? 'refId' : 'saleId')] = $n === 0 ? $s : $saleId;
            $changes[] = TillFixtures::envelope($template['entity'], $payload, $seq++);
        }
    }

    $json = json_encode($changes, JSON_THROW_ON_ERROR);
    $cpu = function (): float {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    };

    $cpuStart = $cpu();
    $wallStart = hrtime(true);
    $response = $sync->push($json);
    $wall = (hrtime(true) - $wallStart) / 1e6;
    $cpuUsed = $cpu() - $cpuStart;

    $retryStart = hrtime(true);
    $retry = $sync->push($json);
    $retryWall = (hrtime(true) - $retryStart) / 1e6;
    $budget = (float) (getenv('SYNC_PUSH_PERF_BUDGET_MS') ?: 10000);

    fwrite(STDERR, sprintf(
        "\n  [perf] sync/push 5,000 rows (%.1f MB JSON, %.0f KB gzip): %.0f ms wall / %.0f ms CPU; retry %.0f ms wall; peak memory %.0f MB; load average %.1f\n",
        strlen($json) / 1048576, strlen((string) gzencode($json)) / 1024, $wall, $cpuUsed, $retryWall,
        memory_get_peak_usage(true) / 1048576, sys_getloadavg()[0] ?? 0,
    ));

    $response->assertOk()->assertExactJson(['acknowledgedSeq' => 5000, 'accepted' => 5000]);
    $retry->assertOk()->assertExactJson(['acknowledgedSeq' => 5000, 'accepted' => 5000]);
    expect(DB::table('sales')->where('branch_id', $sync->leeds->id)->count())->toBe(700)
        ->and(DB::table('stock_movements')->where('register_id', $sync->tills[TillFixtures::TILL_1]->id)->count())->toBe(1400)
        ->and($cpuUsed)->toBeLessThan($budget)
        ->and($wall)->toBeLessThan(25000);
})->group('perf');
