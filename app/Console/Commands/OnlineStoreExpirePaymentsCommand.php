<?php

namespace App\Console\Commands;

use App\Domain\OnlineStore\Actions\ExpireCardPayments;
use Illuminate\Console\Command;

/**
 * Online store OS-9: card orders not paid within the hold (`online_store.card_hold_minutes`) are marked "payment not
 * completed" (ExpireCardPayments). Scheduled every minute; safe to run at any time.
 */
class OnlineStoreExpirePaymentsCommand extends Command
{
    protected $signature = 'online-store:expire-payments';

    protected $description = 'Release the slots of online card orders that were not paid in time';

    public function handle(ExpireCardPayments $expire): int
    {
        $this->info('Released '.$expire->handle().' unpaid card orders.');

        return self::SUCCESS;
    }
}
