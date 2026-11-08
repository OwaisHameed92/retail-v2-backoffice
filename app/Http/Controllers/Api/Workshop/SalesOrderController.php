<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Workshop\Actions\ChangeSalesOrder;
use App\Domain\Workshop\Actions\SaveSalesOrder;
use App\Domain\Workshop\Data\SalesOrderInput;
use App\Domain\Workshop\Data\SalesOrderLineInput;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Queries\ConnectorReference;
use App\Domain\Workshop\Queries\SalesOrderView;
use App\Domain\Workshop\Support\ConnectorException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BikeBook connector sales orders and their lines (WORKSHOP-BIKEBOOK.md §3.6): "Send to POS" makes a WorkshopOrder
 * for the shop's till; changes are new versions until the till takes money (then 409 order_locked).
 */
class SalesOrderController extends Controller
{
    public function store(Request $request, SaveSalesOrder $save, ConnectorReference $reference): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);
        [$order, $created] = $save->handle($connection, SalesOrderInput::from($request->json()->all(), $connection, $reference));

        return response()->json(SalesOrderView::of($order), $created ? 201 : 200);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(SalesOrderView::of($this->order($request, (string) $request->query('id', ''))));
    }

    public function update(Request $request, SaveSalesOrder $save, ConnectorReference $reference): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);
        $body = $request->json()->all();
        $id = trim((string) ($body['id'] ?? $request->query('id', '')));

        if ($id === '') {
            throw ConnectorException::invalid('id (our sales order id) is required.');
        }

        [$order] = $save->handle($connection, SalesOrderInput::from($body, $connection, $reference), $id);

        return response()->json(SalesOrderView::of($order));
    }

    public function destroy(Request $request, ChangeSalesOrder $change): JsonResponse
    {
        $order = $this->order($request, (string) $request->query('id', ''));

        return response()->json(SalesOrderView::of($change->cancel(ConnectorPaging::connection($request), $order->id)));
    }

    public function status(Request $request, string $id, string $status, ChangeSalesOrder $change): JsonResponse
    {
        return response()->json(SalesOrderView::of($change->status(ConnectorPaging::connection($request), $id, $status)));
    }

    public function storeLine(Request $request, ChangeSalesOrder $change, ConnectorReference $reference): JsonResponse
    {
        $body = $request->json()->all();
        $orderId = trim((string) ($body['salesOrderId'] ?? $body['orderId'] ?? $body['salesOrder'] ?? ''));

        if ($orderId === '') {
            throw ConnectorException::invalid('salesOrderId is required.');
        }

        $order = $change->addLine(ConnectorPaging::connection($request), $orderId, SalesOrderLineInput::from($body, 1, $reference));

        return response()->json(SalesOrderView::of($order));
    }

    public function updateLine(Request $request, ChangeSalesOrder $change, ConnectorReference $reference): JsonResponse
    {
        $body = $request->json()->all();
        $lineId = trim((string) ($body['id'] ?? $request->query('id', '')));

        if ($lineId === '') {
            throw ConnectorException::invalid('id (the line id) is required.');
        }

        $line = SalesOrderLineInput::from([...$body, 'id' => $body['externalId'] ?? ''], 1, $reference);

        return response()->json(SalesOrderView::of($change->updateLine(ConnectorPaging::connection($request), $lineId, $line)));
    }

    public function destroyLine(Request $request, ChangeSalesOrder $change): JsonResponse
    {
        $lineId = trim((string) $request->query('id', ''));

        if ($lineId === '') {
            throw ConnectorException::invalid('id (the line id) is required.');
        }

        return response()->json(SalesOrderView::of($change->deleteLine(ConnectorPaging::connection($request), $lineId)));
    }

    private function order(Request $request, string $id): WorkshopOrder
    {
        $id = trim($id);
        $order = $id === '' ? null : WorkshopOrder::query()->where('connection_id', ConnectorPaging::connection($request)->id)->whereKey($id)->first();

        return $order ?? throw ConnectorException::notFound('No sales order with this id for this shop.');
    }
}
