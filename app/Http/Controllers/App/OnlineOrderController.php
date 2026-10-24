<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Actions\ChangeOrderLine;
use App\Domain\OnlineStore\Actions\ResendOrderLink;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Actions\UndoOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Queries\OnlineOrderBoard;
use App\Domain\OnlineStore\Queries\OnlineOrderDetail;
use App\Domain\OnlineStore\Support\OrderRows;
use App\Domain\OnlineStore\Support\PortalOrders;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\ChangeOnlineOrderStatusRequest;
use App\Http\Requests\App\OnlineStore\ChangeOrderLineRequest;
use App\Http\Requests\App\OnlineStore\OnlineOrderBoardRequest;
use App\Http\Requests\App\OnlineStore\SetTakingOrdersRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal → Online orders (OS-4, docs/online-store.md): the board, one order's page and its pick list, moving an
 * order on (and undoing the last move, OSA-21), changing a line (OSA-18), sending the customer their link again
 * (OSA-22), and the "Taking orders" switch. Routes: switch on (404 off), `store.manage`; a one-shop user works
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
        $moved = $change->handle($this->tenancy->require(), $row->id, $to, $request->validated('reason'), $request->boolean('spam'));
        $who = OrderRows::shortName($moved->customer_name);
        $refund = $moved->isPaid() ? ' It was paid by card online: refund it in your DNA portal.' : '';

        return back()->with('success', match ($to) {
            OnlineOrderStatus::Picking => "{$moved->reference} is being picked.",
            OnlineOrderStatus::Ready => $moved->isDelivery() ? "{$moved->reference} is packed and ready to go out." : "{$moved->reference} is ready. {$who} is emailed to come and collect it.",
            OnlineOrderStatus::OutForDelivery => "{$moved->reference} is out for delivery.",
            OnlineOrderStatus::Collected => "{$moved->reference} is collected.",
            OnlineOrderStatus::Delivered => "{$moved->reference} is delivered.",
            OnlineOrderStatus::NotCollected => $moved->isDelivery() ? "{$moved->reference} is marked as not delivered." : "{$moved->reference} is marked as not collected.",
            default => $moved->cancelled_as_spam ? "{$moved->reference} is cancelled as a fake order. Nobody is emailed.{$refund}" : "{$moved->reference} is cancelled. {$who} is emailed with your reason.{$refund}",
        });
    }

    public function undo(string $order, UndoOnlineOrderStatus $undo): RedirectResponse
    {
        $back = $undo->handle($this->tenancy->require(), $this->orders->find($order)->id);

        return back()->with('success', "{$back->reference} is back to ".mb_strtolower($back->status->label($back->fulfilment)).'.');
    }

    public function line(ChangeOrderLineRequest $request, string $order, string $line, ChangeOrderLine $change): RedirectResponse
    {
        $changed = $change->handle($this->tenancy->require(), $this->orders->find($order)->id, $line, (string) $request->validated('action'), $request->validated('quantity'));

        return back()->with('success', "{$changed->reference} changed. New total ".MoneyFormat::format($changed->grandTotal()).'.');
    }

    public function resend(string $order, ResendOrderLink $resend): RedirectResponse
    {
        $sent = $resend->handle($this->tenancy->require(), $this->orders->find($order)->id);

        return back()->with('success', "{$sent->reference}: the order email with a fresh link is on its way to the customer.");
    }

    public function taking(SetTakingOrdersRequest $request, SetTakingOrders $set): RedirectResponse
    {
        $taking = $request->taking();
        $set->handle($this->tenancy->require(), $taking);

        return back()->with('success', $taking ? 'The store is taking orders again.' : 'The store has stopped taking orders. Customers can still look around.');
    }
}
