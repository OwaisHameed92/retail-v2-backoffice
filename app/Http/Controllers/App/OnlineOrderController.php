<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Queries\OnlineOrderBoard;
use App\Domain\OnlineStore\Queries\OnlineOrderDetail;
use App\Domain\OnlineStore\Support\OrderRows;
use App\Domain\OnlineStore\Support\PortalOrders;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\ChangeOnlineOrderStatusRequest;
use App\Http\Requests\App\OnlineStore\OnlineOrderBoardRequest;
use App\Http\Requests\App\OnlineStore\SetTakingOrdersRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal → Online orders (OS-4, docs/online-store.md): the board, one order's page and its pick list, moving an
 * order on, and the "Taking orders" switch. Routes: switch on (404 off), `store.manage`; a one-shop user works
 * on their own shop's orders only (PortalOrders: anything else is a 404).
 */
class OnlineOrderController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly PortalOrders $orders) {}

    public function index(OnlineOrderBoardRequest $request, OnlineOrderBoard $board): Response
    {
        return Inertia::render('app/online-orders/index', $board->for($request->filters()));
    }

    public function show(string $order): Response
    {
        return Inertia::render('app/online-orders/show', OnlineOrderDetail::for($this->orders->find($order), $this->tenancy->require()));
    }

    public function pickList(string $order): Response
    {
        return Inertia::render('app/online-orders/pick-list', OnlineOrderDetail::for($this->orders->find($order), $this->tenancy->require()));
    }

    public function status(ChangeOnlineOrderStatusRequest $request, string $order, ChangeOnlineOrderStatus $change): RedirectResponse
    {
        $row = $this->orders->find($order);
        $to = $request->status();
        $moved = $change->handle($this->tenancy->require(), $row->id, $to, $request->validated('reason'));
        $who = OrderRows::shortName($moved->customer_name);

        return back()->with('success', match ($to) {
            OnlineOrderStatus::Picking => "{$moved->reference} is being picked.",
            OnlineOrderStatus::Ready => "{$moved->reference} is ready. {$who} is emailed to come and collect it.",
            OnlineOrderStatus::Collected => "{$moved->reference} is collected.",
            default => "{$moved->reference} is cancelled. {$who} is emailed with your reason.",
        });
    }

    public function taking(SetTakingOrdersRequest $request, SetTakingOrders $set): RedirectResponse
    {
        $taking = $request->taking();
        $set->handle($this->tenancy->require(), $taking);

        return back()->with('success', $taking ? 'The store is taking orders again.' : 'The store has stopped taking orders. Customers can still look around.');
    }
}
