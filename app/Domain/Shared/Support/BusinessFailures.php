<?php

namespace App\Domain\Shared\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Per-business isolation for the scheduled runs that walk every business (`billing:run`, `alerts:digest`,
 * `anomalies:detect`, `privacy:retention`, `labels:queue-offers`; audit DATA-2/7/8). One business's error is
 * reported (with the task and its company id in the log context), remembered, and the run carries on with the next
 * business. The command asks `companyIds()` at the end and exits non-zero, naming the businesses that failed.
 *
 * Scoped: one per command run (and per test).
 */
#[Scoped]
final class BusinessFailures
{
    /** @var array<string, array<string, true>> task => company ids that failed */
    private array $failed = [];

    /**
     * Run one business's share of a task. On any Throwable: report it, remember the business and return `$default`.
     *
     * @template T
     * @template D
     *
     * @param  callable(): T  $work
     * @param  D  $default
     * @return T|D
     */
    public function attempt(string $task, ?string $companyId, callable $work, mixed $default = null): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            $this->record($task, $companyId, $e);

            return $default;
        }
    }

    /** Report an error of one business (with the task and company id in the log context) and remember it. */
    public function record(string $task, ?string $companyId, Throwable $e): void
    {
        $id = $companyId ?? 'unknown';
        $this->failed[$task][$id] = true;

        Context::scope(fn () => report($e), ['task' => $task, 'company_id' => $id]);
    }

    /**
     * The businesses that failed in tasks starting with `$task` (e.g. "billing:run" for every billing step), in the
     * order they failed, each once.
     *
     * @return list<string>
     */
    public function companyIds(string $task = ''): array
    {
        $ids = [];

        foreach ($this->failed as $name => $companies) {
            if (str_starts_with($name, $task)) {
                $ids = [...$ids, ...array_map('strval', array_keys($companies))];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Forget the failures of tasks starting with `$task` (at the start of a run). */
    public function forget(string $task = ''): void
    {
        foreach (array_keys($this->failed) as $name) {
            if (str_starts_with($name, $task)) {
                unset($this->failed[$name]);
            }
        }
    }
}
