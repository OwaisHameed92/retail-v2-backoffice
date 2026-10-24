<?php

namespace App\Domain\OnlineStore\Listeners;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderStatusChanged;
use App\Domain\OnlineStore\Mail\OnlineOrderCancelledMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReadyMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * An online order moved on (OS-4, queued after commit): the customer is emailed, in the shop's name, when it is
 * ready to collect and when it is cancelled (with the reason). The stage it moved to is taken from the event, so a
 * quick "ready then collected" still sends the ready email. Nothing while the online store is switched off.
 */
final class SendOnlineOrderStatusMail implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly OnlineOrderMessages $messages) {}

    public function handle(OnlineOrderStatusChanged $event): void
    {
        $to = $event->order->status;

        // OS-9: "ready to collect" is for collections only; OSA-1: an order cancelled as spam is never emailed.
        if (($to !== OnlineOrderStatus::Ready && $to !== OnlineOrderStatus::Cancelled) || ($to === OnlineOrderStatus::Ready && $event->order->isDelivery())
            || ($to === OnlineOrderStatus::Cancelled && $event->order->cancelled_as_spam)) {
            return;
        }

        $this->messages->forOrder($event->order->company_id, $event->order->id, function (OnlineOrder $order, OnlineStore $store, Company $company) use ($to): void {
            $data = $this->messages->mailData($order, $store, $company, $this->messages->shopAddress($store, $company), forCustomer: true);

            Mail::to($order->customer_email, $order->customer_name)
                ->queue($to === OnlineOrderStatus::Ready ? new OnlineOrderReadyMail($data) : new OnlineOrderCancelledMail($data));
        });
    }
}
