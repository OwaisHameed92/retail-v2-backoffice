<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\ConnectorException;
use App\Http\Middleware\AuthenticateWorkshopConnector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Paging and delta conventions of the connector (WORKSHOP-BIKEBOOK.md §2): `?page=` (from 1) and `?pageSize=` (≤ 500,
 * default 100), `?lastUpdated=` (ISO 8601 UTC; absent = everything), `?fields=id,updated` for the light list. The
 * answer is the JSON array with `X-Total-Count`, `X-Page`, `X-Page-Size` and, when more pages exist,
 * `Link: <…>; rel="next"`.
 */
final class ConnectorPaging
{
    public static function connection(Request $request): WorkshopConnection
    {
        $connection = $request->attributes->get(AuthenticateWorkshopConnector::ATTRIBUTE);

        return $connection instanceof WorkshopConnection ? $connection : throw ConnectorException::unauthorized();
    }

    /**
     * @return array{page: int, size: int, since: CarbonImmutable|null, light: bool}
     */
    public static function from(Request $request): array
    {
        $page = $request->query('page', '1');
        $size = $request->query('pageSize', $request->query('limit', (string) config('workshop.default_page_size', 100)));
        $max = (int) config('workshop.max_page_size', 500);

        if (! is_string($page) || preg_match('/^\d{1,7}$/', $page) !== 1 || (int) $page < 1) {
            throw ConnectorException::invalid('page must be a whole number from 1.');
        }

        if (! is_string($size) || preg_match('/^\d{1,4}$/', $size) !== 1 || (int) $size < 1 || (int) $size > $max) {
            throw ConnectorException::invalid("pageSize must be a whole number from 1 to {$max}.");
        }

        $fields = strtolower(str_replace(' ', '', (string) $request->query('fields', '')));

        return ['page' => (int) $page, 'size' => (int) $size, 'since' => self::since($request->query('lastUpdated')), 'light' => $fields === 'id,updated' || $fields === 'updated,id'];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{page: int, size: int}  $paging
     */
    public static function reply(Request $request, array $items, int $total, array $paging): JsonResponse
    {
        $headers = ['X-Total-Count' => (string) $total, 'X-Page' => (string) $paging['page'], 'X-Page-Size' => (string) $paging['size']];

        if ($total > $paging['page'] * $paging['size']) {
            $next = $request->fullUrlWithQuery(['page' => $paging['page'] + 1]);
            $headers['Link'] = "<{$next}>; rel=\"next\"";
        }

        return response()->json($items, 200, $headers);
    }

    private static function since(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            throw ConnectorException::invalid('lastUpdated must be an ISO 8601 date and time, e.g. 2026-11-08T09:00:00Z.');
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw ConnectorException::invalid('lastUpdated must be an ISO 8601 date and time, e.g. 2026-11-08T09:00:00Z.');
        }
    }
}
