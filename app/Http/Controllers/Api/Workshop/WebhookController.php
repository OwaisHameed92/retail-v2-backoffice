<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Workshop\Actions\SaveWorkshopWebhook;
use App\Domain\Workshop\Support\ConnectorException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * BikeBook's webhook on us (WORKSHOP-BIKEBOOK.md §3.8 "Webhook CRUD"): one per connection; the secret is never
 * answered back.
 */
class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);

        return response()->json($connection->webhook_url === null ? [] : [SaveWorkshopWebhook::view($connection)]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);

        if ($connection->webhook_url === null || $connection->webhook_id !== $id) {
            throw ConnectorException::notFound('No webhook with this id.');
        }

        return response()->json(SaveWorkshopWebhook::view($connection));
    }

    public function store(Request $request, SaveWorkshopWebhook $save): JsonResponse
    {
        $connection = $save->save(ConnectorPaging::connection($request), $request->json()->all(), true);

        return response()->json(SaveWorkshopWebhook::view($connection), 201);
    }

    public function update(Request $request, SaveWorkshopWebhook $save): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);
        $id = trim((string) $request->json('id', ''));

        if ($id !== '' && $connection->webhook_id !== null && $id !== $connection->webhook_id) {
            throw ConnectorException::notFound('No webhook with this id.');
        }

        return response()->json(SaveWorkshopWebhook::view($save->save($connection, $request->json()->all(), false)));
    }

    public function destroy(Request $request, string $id, SaveWorkshopWebhook $save): Response
    {
        $connection = ConnectorPaging::connection($request);

        if ($connection->webhook_url !== null && $connection->webhook_id !== $id) {
            throw ConnectorException::notFound('No webhook with this id.');
        }

        $save->delete($connection);

        return response()->noContent();
    }
}
