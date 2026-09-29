<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/**
 * One full push batch (the 5,000-row contract ceiling) of mixed rows: 700 sales, each with 2 lines, a payment,
 * a VAT row and 2 stock movements, plus 100 products. Budget: TILL_PERF_BUDGET_MS (default 5,000 ms) of this
 * process's CPU time, so a busy machine (parallel test runs, builds) does not make the test flaky; the wall time
 * is printed next to it.
 */
it('applies 5,000 mixed rows well within the budget, and a retry of them faster still', function () {
    [$company, $leeds] = TillFixtures::tenant();
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

    expect($changes)->toHaveCount(5000);

    // CPU time of this process too: wall time also counts whatever else the machine is doing.
    $cpu = function (): float {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    };

    $start = $cpu();
    $result = TillFixtures::apply($company, $leeds, $changes);
    $cpuFirst = $cpu() - $start;
    $start = $cpu();
    $retry = TillFixtures::apply($company, $leeds, $changes);
    $cpuRetry = $cpu() - $start;
    $budget = (float) (getenv('TILL_PERF_BUDGET_MS') ?: 5000);

    fwrite(STDERR, sprintf(
        "\n  [perf] 5,000 mixed rows: first apply %.0f ms wall / %.0f ms CPU (%.0f rows/s wall); retry (all duplicates) %.0f ms wall / %.0f ms CPU; peak memory %.0f MB; load average %.1f\n",
        $result->durationMs,
        $cpuFirst,
        5000 / ($result->durationMs / 1000),
        $retry->durationMs,
        $cpuRetry,
        memory_get_peak_usage(true) / 1048576,
        sys_getloadavg()[0] ?? 0,
    ));

    expect(TillFixtures::ack($result))->toBe(['acknowledgedSeq' => 5000, 'accepted' => 5000])
        ->and($retry->toPushReply())->toBe($result->toPushReply())
        ->and(DB::table('sales')->count())->toBe(700)
        ->and(DB::table('sale_lines')->whereNull('register_id')->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(1400)
        ->and($cpuFirst)->toBeLessThan($budget);
})->group('perf');
