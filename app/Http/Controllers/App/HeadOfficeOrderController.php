<?php

namespace App\Http\Controllers\App;

use App\Domain\Purchasing\Actions\ChangeHeadOfficeOrderStatus;
use App\Domain\Purchasing\Actions\SaveHeadOfficeOrder;
use App\Domain\Purchasing\Queries\OrderForm;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Purchasing\HeadOfficeOrderRequest;
use App\Http\Requests\App\Purchasing\OrderStatusRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Head-office orders (module 5.2, contract §10.6, `company.can:purchasing.manage`, every shop only): draft an order
 * for one shop, change it, send it or cancel it while the portal still owns it. The shop's till pulls it at its next
 * sync; once the shop sends, cancels or receives it, it is the shop's and read only here.
 */
class HeadOfficeOrderController extends Controller
{
    public function create(Request $request): Response
    {
        abort_unless(PurchasingPage::canManage(), 403);

        return Inertia::render('app/purchasing/order-form', OrderForm::for($request, null));
    }

    public function store(HeadOfficeOrderRequest $request, SaveHeadOfficeOrder $save): RedirectResponse
    {
        $shop = Branch::query()->findOrFail($request->validated('shopId'));
        $order = $save->handle($shop, $request->validated());

        return redirect()->route('app.purchasing.orders.show', $order->id)->with('success', self::saved($order, $shop->name));
    }

    public function edit(Request $request, string $order): Response|RedirectResponse
    {
        abort_unless(PurchasingPage::canManage(), 403);
        $model = PurchaseOrder::query()->findOrFail($order);

        if (! HeadOfficeOrders::portalOwns($model)) {
            return redirect()->route('app.purchasing.orders.show', $model->id)->with('error', HeadOfficeOrders::lockedReason($model));
        }

        return Inertia::render('app/purchasing/order-form', OrderForm::for($request, $model));
    }

    public function update(HeadOfficeOrderRequest $request, string $order, SaveHeadOfficeOrder $save): RedirectResponse
    {
        $model = PurchaseOrder::query()->findOrFail($order);
        $shop = Branch::query()->withTrashed()->findOrFail($model->branch_id);
        $saved = $save->handle($shop, $request->validated(), $model);

        return redirect()->route('app.purchasing.orders.show', $saved->id)->with('success', self::saved($saved, $shop->name));
    }

    public function send(OrderStatusRequest $request, string $order, ChangeHeadOfficeOrderStatus $change): RedirectResponse
    {
        $saved = $change->handle(PurchaseOrder::query()->findOrFail($order), PurchaseOrderStatus::Sent);

        return back()->with('success', "{$saved->reference} is sent. The shop can book the delivery in once its till syncs.");
    }

    public function cancel(OrderStatusRequest $request, string $order, ChangeHeadOfficeOrderStatus $change): RedirectResponse
    {
        $saved = $change->handle(PurchaseOrder::query()->findOrFail($order), PurchaseOrderStatus::Cancelled, $request->validated('reason'));

        return back()->with('success', "{$saved->reference} is cancelled. The shop's till gets the change at its next sync.");
    }

    private static function saved(PurchaseOrder $order, string $shop): string
    {
        return $order->status === PurchaseOrderStatus::Sent
            ? "{$order->reference} is sent. {$shop} can book the delivery in once its till syncs."
            : "{$order->reference} is saved as a draft. {$shop} sees it at the next sync, but cannot receive it until you send it.";
    }
}
