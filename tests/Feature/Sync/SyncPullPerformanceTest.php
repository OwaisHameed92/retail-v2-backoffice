<?php

use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 2.5: a full pull page (5,000 products another branch pushed, stamped by this pull) through HTTP must finish
 * well inside the till's 30 s timeout (contract §3). Budget: SYNC_PULL_PERF_BUDGET_MS (default 10,000 ms) of CPU.
 */
it('sends a 5,000-row pull page, stamping it first, well inside the till\'s 30-second timeout', function () {
    $sync = new SyncApiFixtures($this);
    $product = TillFixtures::sample('entities/Product.json');
    $changes = [];

    for ($p = 1; $p <= 5000; $p++) {
        $changes[] = TillFixtures::envelope('Product', [...$product, 'id' => '01K6P'.str_pad((string) $p, 21, '0', STR_PAD_LEFT), 'name' => "Product {$p}", 'sellPrice' => 1 + $p / 100], $p);
    }

    $sync->push($changes)->assertOk();

    $cpu = function (): float {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000;
    };

    $cpuStart = $cpu();
    $wallStart = hrtime(true);
    $reply = $sync->pull(0, 5000, ['Accept-Encoding' => 'gzip'], bradford: true);
    $wall = (hrtime(true) - $wallStart) / 1e6;
    $cpuUsed = $cpu() - $cpuStart;

    $againStart = hrtime(true);
    $again = $sync->pull(0, 5000, bradford: true);
    $againWall = (hrtime(true) - $againStart) / 1e6;
    $json = (string) gzdecode((string) $reply->getContent());
    $budget = (float) (getenv('SYNC_PULL_PERF_BUDGET_MS') ?: 10000);

    fwrite(STDERR, sprintf(
        "\n  [perf] sync/pull 5,000 rows (%.1f MB JSON, %.0f KB gzip): %.0f ms wall / %.0f ms CPU incl. stamping; again %.0f ms wall\n",
        strlen($json) / 1048576, strlen((string) $reply->getContent()) / 1024, $wall, $cpuUsed, $againWall,
    ));

    $body = json_decode($json, true);
    expect($body['changes'])->toHaveCount(5000)
        ->and($body['highestVersion'])->toBe(5000)
        ->and($body['hasMore'])->toBeFalse()
        ->and($again->json('changes'))->toHaveCount(5000)
        ->and($cpuUsed)->toBeLessThan($budget)
        ->and($wall)->toBeLessThan(25000);
})->group('perf');
