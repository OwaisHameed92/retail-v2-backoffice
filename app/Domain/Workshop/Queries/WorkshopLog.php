<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Workshop\Models\WorkshopCallLog;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The connection's sync log on the admin card (WORKSHOP-BIKEBOOK.md §8): the last 1,000 BikeBook calls (time, call,
 * result, ms) and the webhook deliveries (time, event, order, status, error) with Replay; CSV export of either. No
 * token, key, body or address is ever in them. Read across companies by connection (admin code).
 */
final class WorkshopLog
{
    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public static function calls(Request $request, WorkshopConnection $connection): array
    {
        return TableQuery::from($request)->searchable(['path', 'request_id'])->sortable(['created_at', 'status', 'duration_ms'])->defaultSort('created_at', 'desc')
            ->paginate(WorkshopCallLog::withoutCompanyScope()->where('connection_id', $connection->id), fn (WorkshopCallLog $row) => self::call($row));
    }

    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public static function deliveries(Request $request, WorkshopConnection $connection): array
    {
        return TableQuery::from($request)->searchable(['event', 'order_id', 'id'])->sortable(['created_at', 'attempts'])->defaultSort('created_at', 'desc')
            ->paginate(WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id), fn (WorkshopWebhookDelivery $row) => self::delivery($row));
    }

    public static function csv(WorkshopConnection $connection, string $kind): StreamedResponse
    {
        $name = 'bikebook-'.($kind === 'deliveries' ? 'webhooks' : 'calls').'-'.now('UTC')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($connection, $kind): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            if ($kind === 'deliveries') {
                fputcsv($out, ['Time (UTC)', 'Event id', 'Event', 'Order', 'State', 'Attempts', 'Last status', 'Error'], escape: '\\');

                foreach (WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id)->orderByDesc('id')->limit(1000)->cursor() as $row) {
                    $d = self::delivery($row);
                    fputcsv($out, [$d['createdAt'], $d['id'], $d['event'], $d['orderId'], $d['state'], $d['attempts'], $d['lastStatus'], $d['lastError']], escape: '\\');
                }
            } else {
                fputcsv($out, ['Time (UTC)', 'Method', 'Call', 'Status', 'Milliseconds', 'Auth', 'BikeBook request id', 'Error'], escape: '\\');

                foreach (WorkshopCallLog::withoutCompanyScope()->where('connection_id', $connection->id)->orderByDesc('id')->limit(1000)->cursor() as $row) {
                    $c = self::call($row);
                    fputcsv($out, [$c['createdAt'], $c['method'], $c['path'], $c['status'], $c['durationMs'], $c['auth'], $c['requestId'], $c['error']], escape: '\\');
                }
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function call(WorkshopCallLog $row): array
    {
        return [
            'id' => $row->id, 'createdAt' => ApiDate::format($row->created_at), 'method' => $row->method, 'path' => $row->path,
            'status' => $row->status, 'durationMs' => $row->duration_ms, 'auth' => $row->auth, 'requestId' => $row->request_id, 'error' => $row->error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function delivery(WorkshopWebhookDelivery $row): array
    {
        return [
            'id' => $row->id, 'createdAt' => ApiDate::format($row->created_at), 'event' => $row->event->value, 'orderId' => $row->order_id,
            'state' => $row->state(), 'attempts' => $row->attempts, 'lastStatus' => $row->last_status, 'lastError' => $row->last_error,
            'nextAttemptAt' => ApiDate::format($row->next_attempt_at), 'deliveredAt' => ApiDate::format($row->delivered_at), 'failedAt' => ApiDate::format($row->failed_at),
        ];
    }
}
