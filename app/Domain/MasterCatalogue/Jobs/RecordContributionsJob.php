<?php

namespace App\Domain\MasterCatalogue\Jobs;

use App\Domain\MasterCatalogue\Actions\RecordContributions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Adds unknown barcodes from tills to the admin review queue. Its payload is the barcode and name only (see
 * CollectUnknownBarcodes): nothing in the queue or the failed-jobs table can point at a business.
 */
class RecordContributionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  list<array{barcode: string, name: string}>  $items
     */
    public function __construct(public readonly array $items) {}

    public function handle(RecordContributions $record): void
    {
        $record->handle($this->items);
    }
}
