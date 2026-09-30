<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;

/**
 * `--company=*`, `--from=` and `--to=` of the reports:* commands.
 *
 * @mixin Command
 */
trait ReadsReportRange
{
    /**
     * @return list<string>
     */
    protected function companies(): array
    {
        return array_values(array_filter((array) $this->option('company'), fn ($id) => is_string($id) && $id !== ''));
    }

    /**
     * @return array{0: string|null, 1: string|null}|null null when a date is not Y-m-d
     */
    protected function range(): ?array
    {
        $range = [];

        foreach (['from', 'to'] as $name) {
            $value = $this->option($name);

            if ($value !== null && preg_match('/^\d{4}-\d\d-\d\d$/', $value) !== 1) {
                $this->error("--{$name} must be a date like 2026-09-23.");

                return null;
            }

            $range[] = $value;
        }

        return [$range[0], $range[1]];
    }
}
