<?php

namespace App\Domain\OnlineStore\Listeners;

use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Mail\NewOnlineOrderMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineOrderMessages;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A customer placed an online order (OS-4, queued after commit): "New online order W-0001" to the shop's order
 * address, a bell entry for everyone who runs the online store, and "We've got your order" to the customer in the
 * shop's name. Nothing at all while the online store is switched off.
 */
final class SendNewOnlineOrderMessages implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly OnlineOrderMessages $messages) {}

    public function handle(OnlineOrderPlaced $event): void
    {
        $this->messages->forOrder($event->order->company_id, $event->order->id, function (OnlineOrder $order, OnlineStore $store, Company $company): void {
            $shop = $this->messages->shopAddress($store, $company);

            if ($shop !== null) {
                Mail::to($shop)->queue(new NewOnlineOrderMail($this->messages->mailData($order, $store, $company, null, forCustomer: false)));
            } else {
                Log::warning('Online order: no address to tell the shop', ['company_id' => $company->id, 'order' => $order->reference]);
            }

            $this->messages->notifyStaff($order, $company);

            Mail::to($order->customer_email, $order->customer_name)
                ->queue(new OnlineOrderReceivedMail($this->messages->mailData($order, $store, $company, $shop, forCustomer: true)));
        });
    }
}
