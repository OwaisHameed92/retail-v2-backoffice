<?php

namespace App\Domain\Workshop\Support;

use App\Domain\Workshop\Models\WorkshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The connector's call log (WORKSHOP-BIKEBOOK.md §2, §8): method, path with its query string (keys are refused before
 * this, so none is ever there), status, time taken, BikeBook's request id. Never a body, token, key or address. Keeps
 * the last 1,000 calls per connection; also moves the connection's "last BikeBook call" (at most once a minute).
 */
final class CallLogger
{
    public function log(WorkshopConnection $connection, Request $request, int $status, int $milliseconds, string $auth, ?string $error = null): void
    {
        $query = $request->getQueryString();
        $path = '/'.ltrim($request->path(), '/').($query === null || $query === '' ? '' : '?'.$query);
        $requestId = $request->header('X-Request-Id') ?? $request->header('Request-Id') ?? $request->header('X-Correlation-Id');
        $now = CarbonImmutable::now('UTC');

        DB::table('workshop_call_logs')->insert([
            'company_id' => $connection->company_id,
            'connection_id' => $connection->id,
            'method' => substr($request->method(), 0, 10),
            'path' => mb_substr($path, 0, 255),
            'status' => $status,
            'duration_ms' => max(0, $milliseconds),
            'request_id' => is_string($requestId) && $requestId !== '' ? mb_substr($requestId, 0, 100) : null,
            'auth' => $auth,
            'error' => $error === null ? null : mb_substr($error, 0, 255),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        self::prune('workshop_call_logs', $connection->id, 'id');

        if ($connection->last_pulled_at === null || $connection->last_pulled_at->lessThan($now->subMinute())) {
            DB::table('workshop_connections')->where('id', $connection->id)->update(['last_pulled_at' => $now->format('Y-m-d H:i:s')]);
        }
    }

    /** Deletes a connection's rows past the newest 1,000 (by `$order`, newest first). */
    public static function prune(string $table, string $connectionId, string $order, ?callable $onlyFinished = null): void
    {
        $keep = max(1, (int) config('workshop.log_keep', 1000));
        $query = DB::table($table)->where('connection_id', $connectionId);

        if ($onlyFinished !== null) {
            $onlyFinished($query);
        }

        $cut = (clone $query)->orderByDesc($order)->offset($keep)->limit(1)->value($order);

        if ($cut !== null) {
            $query->where($order, '<=', $cut)->delete();
        }
    }
}
