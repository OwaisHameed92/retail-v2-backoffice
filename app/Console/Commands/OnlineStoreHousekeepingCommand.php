<?php

namespace App\Console\Commands;

use App\Domain\OnlineStore\Actions\AnonymiseOnlineOrders;
use App\Domain\OnlineStore\Actions\CloseUncollectedOrders;
use App\Domain\OnlineStore\Support\StoreOldSlugs;
use Illuminate\Console\Command;

/**
 * Online store order housekeeping (audit OSA-21, OSA-22): ready orders nobody collected become "not collected"
 * (CloseUncollectedOrders), and customers' details are removed from orders finished long ago (AnonymiseOnlineOrders). OS-11: store addresses changed more than 90 days ago are let go (StoreOldSlugs).
 * Scheduled hourly; safe to run at any time.
 */
class OnlineStoreHousekeepingCommand extends Command
{
    protected $signature = 'online-store:housekeeping';

    protected $description = 'Close uncollected online orders and anonymise old ones';

    public function handle(CloseUncollectedOrders $close, AnonymiseOnlineOrders $anonymise): int
    {
        $this->info('Not collected: '.$close->handle().'. Anonymised: '.$anonymise->handle().'. Old store addresses let go: '.StoreOldSlugs::prune().'.');

        return self::SUCCESS;
    }
}
