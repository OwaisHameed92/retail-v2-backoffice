<?php

namespace Tests\Feature\Shared;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes one business fail inside a scheduled run (audit DATA-2/7/8): from the first query on `$table` that names one
 * of `$companyIds` (the business the run reaches first), every query on that table naming that business throws, as
 * a bad row or a lost connection would. The other businesses run normally, so a run that isolates businesses still
 * serves them whatever order it takes them in. `stop()` before asserting with queries of your own.
 */
final class FailingBusiness
{
    public ?string $failed = null;

    private bool $armed = true;

    /**
     * @param  list<string>  $companyIds
     */
    public static function on(string $table, array $companyIds): self
    {
        $state = new self;

        DB::listen(function (QueryExecuted $query) use ($state, $table, $companyIds) {
            if (! $state->armed || preg_match('/[`"]'.preg_quote($table, '/').'[`"]/', $query->sql) !== 1) {
                return;
            }

            foreach ($companyIds as $id) {
                if (in_array($id, $query->bindings, true) && ($state->failed ?? $id) === $id) {
                    $state->failed = $id;

                    throw new RuntimeException("Simulated failure for business {$id}");
                }
            }
        });

        return $state;
    }

    public function stop(): void
    {
        $this->armed = false;
    }

    /**
     * The business of `$companyIds` that did not fail.
     *
     * @param  list<string>  $companyIds
     */
    public function other(array $companyIds): string
    {
        return (string) collect($companyIds)->first(fn (string $id) => $id !== $this->failed);
    }
}
