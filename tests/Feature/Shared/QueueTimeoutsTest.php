<?php

use App\Domain\Catalogue\Jobs\ApplyProductImportJob;
use App\Domain\MasterCatalogue\Jobs\MasterImportJob;
use App\Domain\Partners\Jobs\SendPartnerWebhookJob;
use App\Domain\Purchasing\Jobs\ReadInvoiceJob;
use App\Domain\Reporting\Jobs\ProcessDirtyReportDaysJob;
use App\Domain\Sales\Jobs\BuildSalesExportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/*
 * Audit DATA-1: the queue hands a reserved job to another worker after `retry_after`. Every job must finish, or be
 * killed by its own or the worker's timeout, before that, or a second worker runs a copy of a job still running.
 * The servers do not set *_QUEUE_RETRY_AFTER, so the config default is what runs.
 */

/** @return array{timeout: int, stopwaitsecs: int} */
function workerConf(): array
{
    $conf = (string) file_get_contents(base_path('deploy/supervisor/retail-v2-worker.conf'));
    preg_match('/queue:work .*--timeout=(\d+)/', $conf, $timeout);
    preg_match('/^stopwaitsecs=(\d+)$/m', $conf, $stop);

    return ['timeout' => (int) ($timeout[1] ?? 0), 'stopwaitsecs' => (int) ($stop[1] ?? 0)];
}

/**
 * Every queued class in app/ (jobs, queued mail, queued listeners) with its own timeout, null when it has none.
 *
 * @return array<class-string, int|null>
 */
function queuedTimeouts(): array
{
    $timeouts = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
        $timeouts[$class] = is_int($timeout) ? $timeout : null;
    }

    return $timeouts;
}

test('retry_after is longer than the longest job and the worker timeout', function () {
    $worker = workerConf();

    foreach (['redis', 'database'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBe(1900);
    }

    expect($worker['timeout'])->toBe(1800)
        ->and($worker['timeout'])->toBeLessThan(1900)
        ->and($worker['stopwaitsecs'])->toBeGreaterThanOrEqual($worker['timeout'] + 10);
});

test('every queued class\'s own timeout is below retry_after (the others get the worker\'s --timeout)', function () {
    $timeouts = queuedTimeouts();

    expect($timeouts)->toHaveKeys([BuildSalesExportJob::class, ProcessDirtyReportDaysJob::class, ApplyProductImportJob::class]);

    foreach ($timeouts as $class => $timeout) {
        expect($timeout === null || $timeout < 1900)->toBeTrue("{$class} timeout {$timeout} is not below retry_after");
    }

    expect($timeouts[BuildSalesExportJob::class])->toBe(1800)
        ->and($timeouts[ProcessDirtyReportDaysJob::class])->toBe(600)
        ->and($timeouts[ApplyProductImportJob::class])->toBe(300)
        ->and($timeouts[MasterImportJob::class])->toBe(300)
        ->and($timeouts[ReadInvoiceJob::class])->toBe(300)
        ->and((new SendPartnerWebhookJob('01K5T0Q8C4000000000000E001'))->timeout)->toBeLessThan(1900);
});
