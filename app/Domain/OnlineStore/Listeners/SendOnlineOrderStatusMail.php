<?php

namespace App\Domain\OnlineStore\Listeners;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderStatusChanged;
use App\Domain\OnlineStore\Mail\OnlineOrderCancelledMail;
use App\Domain\OnlineStore\Mail\OnlineOrderChangedMail;
use App\Domain\OnlineStore\Mail\OnlineOrderDeliveredMail;
use App\Domain\OnlineStore\Mail\OnlineOrderOnItsWayMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReadyMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * An online order moved on (OS-4, queued after commit): the customer is emailed, in the shop's name, when it is
 * ready to collect, out for delivery or delivered (OS-9), and when it is cancelled (with the reason). The stage it moved to is taken from the event, so a
 * quick "ready then collected" still sends the ready email. Nothing while the online store is switched off.
 */
final class SendOnlineOrderStatusMail implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly OnlineOrderMessages $messages) {}

    public function handle(OnlineOrderStatusChanged $event): void
    {
        $to = $event->order->status;
        $delivery = $event->order->isDelivery();

        // OS-9: "ready to collect" is for collections only, "on its way" and "delivered" for deliveries; OSA-1: an order
        // cancelled as spam is never emailed.
        $mail = match (true) {
            $to === OnlineOrderStatus::Ready && ! $delivery => OnlineOrderReadyMail::class,
            $to === OnlineOrderStatus::OutForDelivery => OnlineOrderOnItsWayMail::class,
            $to === OnlineOrderStatus::Delivered => OnlineOrderDeliveredMail::class,
            $to === OnlineOrderStatus::Cancelled && ! $event->order->cancelled_as_spam => OnlineOrderCancelledMail::class,
            default => null,
        };

        // OSA-18: once picked (ready), a customer whose order the shop changed is told what they get now.
        $changed = $to === OnlineOrderStatus::Ready;

        if ($mail === null && ! $changed) {
            return;
        }

        $this->messages->forOrder($event->order->company_id, $event->order->id, function (OnlineOrder $order, OnlineStore $store, Company $company) use ($mail, $changed): void {
            $data = $this->messages->mailData($order, $store, $company, $this->messages->shopAddress($store, $company), forCustomer: true);

            if ($changed && $order->lines->contains(fn (OnlineOrderLine $line) => $line->outcome !== null)) {
                Mail::to($order->customer_email, $order->customer_name)->queue(new OnlineOrderChangedMail($data));
            }

            if ($mail !== null) {
                Mail::to($order->customer_email, $order->customer_name)->queue(new $mail($data));
            }
        });
    }
}
