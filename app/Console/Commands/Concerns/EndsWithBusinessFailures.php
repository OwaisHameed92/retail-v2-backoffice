<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;

/**
 * The end of a scheduled command that walks every business (audit DATA-2/7/8): success, or the ids of the businesses
 * that failed (each already reported; every other business ran) and a non-zero exit code.
 *
 * @mixin Command
 */
trait EndsWithBusinessFailures
{
    /**
     * @param  list<string>  $companyIds
     */
    protected function endWith(array $companyIds, string $what): int
    {
        if ($companyIds === []) {
            return self::SUCCESS;
        }

        $this->error(ucfirst($what).' failed for '.count($companyIds).' business(es), reported; every other business ran: '.implode(', ', $companyIds));

        return self::FAILURE;
    }
}
